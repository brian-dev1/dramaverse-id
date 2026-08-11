<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentRegion;
use App\Models\MembershipPlan;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipController extends AdminCrudController
{
    protected function model(): string
    {
        return MembershipPlan::class;
    }

    protected function routeKey(): string
    {
        return 'membership';
    }

    protected function label(): string
    {
        return 'Paket Membership';
    }

    protected function columns(): array
    {
        return [
            'Nama'          => 'name',
            'Slug'          => 'slug',
            'Wilayah'       => 'region',
            'Harga'         => 'price',
            'Mata uang'     => 'currency',
            'Durasi (hari)' => 'duration',
            'Badge'         => 'badge',
            'Langganan'     => 'subscriptions_count',
            'Urutan'        => 'sort_order',
            'Aktif'         => 'is_active',
        ];
    }

    protected function sortable(): array
    {
        return ['name', 'price', 'duration', 'sort_order'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    protected function withCount(): array
    {
        return ['subscriptions'];
    }

    protected function filters(): array
    {
        return [
            'is_active' => [
                'label'   => 'Status',
                'options' => [1 => 'Aktif', 0 => 'Nonaktif'],
            ],
            'region' => [
                'label'   => 'Wilayah',
                'options' => PaymentRegion::options(),
            ],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'slug'        => ['nullable', 'string', 'max:50', 'alpha_dash',
                              Rule::unique('membership_plans', 'slug')->ignore($model?->getKey())],
            'region'      => ['required', Rule::enum(PaymentRegion::class)],
            'price'       => ['required', 'string', 'max:30'],
            'currency'    => ['required', 'string', 'size:3', Rule::in(array_keys(Uang::PILIHAN))],
            'duration'    => ['required', 'integer', 'min:1', 'max:36500'],
            'description' => ['nullable', 'string', 'max:500'],
            'benefits'    => ['nullable', 'string', 'max:1000'],
            'badge'       => ['nullable', 'string', 'max:30'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'   => ['boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? Str::slug($data['slug'])
            : Str::slug($data['name']);

        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? 'IDR')));

        $data['price'] = $this->normalizeHarga($data['price'] ?? 0, $data['currency']);

        $data['is_active'] = $request->boolean('is_active');

        // Benefit diisi satu per baris di textarea, disimpan sebagai JSON.
        $data['benefits'] = collect(preg_split('/\r\n|\r|\n/', (string) ($data['benefits'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all() ?: null;

        return $data;
    }


    /**
     * Terima harga yang diketik bebas oleh admin.
     *
     * ## Kenapa tidak lagi "buang semua yang bukan angka"
     *
     * Versi sebelumnya menghapus setiap karakter non-digit, dan itu benar
     * selama harga hanya pernah Rupiah: "Rp 1.500" jadi 1500, titik ribuannya
     * hilang tanpa merugikan siapa pun.
     *
     * Begitu ada paket Ringgit, aturan yang sama menghancurkan harga.
     * "RM 14.90" menjadi 1490 — seratus kali lipat, tersimpan diam-diam,
     * dan baru ketahuan ketika ada yang menerima tagihan RM 1.490.
     *
     * Sekarang: Rupiah tetap bilangan bulat (sen Rupiah tidak dipakai di mana
     * pun), sedangkan mata uang lain menyimpan dua desimal.
     *
     * Pemisah desimalnya ditentukan dari POSISI, bukan dari jenis tandanya.
     * Baik "14.90" maupun "14,90" berarti empat belas koma sembilan puluh,
     * karena tanda terakhir diikuti tepat dua digit. Sebaliknya "1.234"
     * dan "1,234" sama-sama seribu dua ratus tiga puluh empat, karena tiga
     * digit di belakang tanda tidak pernah berarti pecahan.
     */
    private function normalizeHarga(mixed $value, string $currency): float
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return 0;
        }

        // Buang huruf dan simbol mata uang, sisakan digit dan pemisah.
        $bersih = preg_replace('/[^\d.,]/', '', $raw) ?? '';

        if ($bersih === '') {
            return 0;
        }

        if (strtoupper($currency) === 'IDR') {
            return (float) max(0, (int) preg_replace('/\D/', '', $bersih));
        }

        // Tanda pemisah terakhir yang diikuti satu atau dua digit adalah
        // pemisah desimal; sisanya pemisah ribuan yang tinggal dibuang.
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $bersih, $cocok) === 1) {
            $bulat   = preg_replace('/\D/', '', $cocok[1]) ?: '0';
            $pecahan = str_pad($cocok[2], 2, '0');

            return max(0, (float) ($bulat.'.'.$pecahan));
        }

        return (float) max(0, (int) preg_replace('/\D/', '', $bersih));
    }
    protected function formData(?Model $model = null): array
    {
        return [
            // Textarea menampilkan benefit satu per baris.
            'benefitsText' => $model?->benefits ? implode("\n", $model->benefits) : '',
            'regionOptions'   => PaymentRegion::options(),
            'currencyOptions' => Uang::PILIHAN,
        ];
    }

    protected function bulkActions(): array
    {
        return [
            'activate'   => 'Aktifkan',
            'deactivate' => 'Nonaktifkan',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        return match ($action) {
            'activate'   => $query->update(['is_active' => true]),
            'deactivate' => $query->update(['is_active' => false]),
            default      => 0,
        };
    }

    /**
     * Paket yang masih dipakai langganan tidak boleh dihapus — riwayat
     * pembayaran akan kehilangan acuan.
     */
    public function destroy(int $id): \Illuminate\Http\RedirectResponse
    {
        $plan = $this->findOrFail($id);

        if ($plan->subscriptions()->exists()) {
            return back()->withErrors([
                'plan' => 'Paket ini masih dipakai langganan. Nonaktifkan saja, jangan dihapus.',
            ]);
        }

        return parent::destroy($id);
    }
}
