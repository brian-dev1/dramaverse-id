<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentDriver;
use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Services\Admin\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pengaturan provider pembayaran.
 *
 * Bentuknya mengikuti Storage Manager (7.2): daftar, tambah, ubah kredensial,
 * aktif/nonaktif, jadikan default. Alasannya juga sama — provider adalah hal
 * yang berubah saat sistem sedang berjalan, dan menunggu deploy untuk
 * mematikan gateway yang bermasalah berarti berjam-jam tanpa bisa menerima
 * pembayaran.
 */
class PaymentProviderController extends Controller
{
    public function index(): View
    {
        return view('web.pages.admin.payment-provider', [
            'providers' => PaymentProvider::query()
                ->withCount('transactions')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'drivers' => PaymentDriver::options(),
            'fields'  => collect(PaymentDriver::cases())
                ->mapWithKeys(fn (PaymentDriver $d) => [$d->value => $d->requiredFields()])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:60'],
            'driver'      => ['required', Rule::enum(PaymentDriver::class)],
            'mode'        => ['required', 'in:sandbox,live'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_flat'    => ['nullable', 'numeric', 'min:0'],
            'instruction' => ['nullable', 'string', 'max:2000'],
        ]);

        $provider = PaymentProvider::create([
            'name'        => $data['name'],
            'slug'        => $this->uniqueSlug($data['name']),
            'driver'      => $data['driver'],
            'mode'        => $data['mode'],
            'fee_percent' => $data['fee_percent'] ?? 0,
            'fee_flat'    => $data['fee_flat'] ?? 0,
            'instruction' => $data['instruction'] ?? null,
            // Selalu nonaktif saat dibuat. Kredensialnya belum diisi, dan
            // provider aktif tanpa kredensial gagal di tengah checkout —
            // tempat paling buruk untuk gagal. Pola yang sama dengan
            // storage provider di 7.2B.
            'is_active'   => false,
        ]);

        app(ActivityLogger::class)->log('create', 'payment-provider', $provider);

        return redirect()
            ->route('admin.payment-provider.index')
            ->with('status', 'Provider ditambahkan. Isi kredensialnya, lalu aktifkan.');
    }

    /**
     * Simpan kredensial dan pengaturan.
     *
     * Field kredensial yang dikirim kosong TIDAK menimpa yang tersimpan.
     * Form tidak pernah menampilkan nilai lamanya — menampilkan secret key di
     * atribut value HTML sama saja dengan tidak mengenkripsinya — jadi kosong
     * berarti "tidak diubah", bukan "dikosongkan".
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $provider = PaymentProvider::findOrFail($id);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:60'],
            'mode'          => ['required', 'in:sandbox,live'],
            'fee_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_flat'      => ['nullable', 'numeric', 'min:0'],
            'instruction'   => ['nullable', 'string', 'max:2000'],
            'credentials'   => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);

        $kredensial = $provider->credentials ?? [];

        foreach ($data['credentials'] ?? [] as $field => $nilai) {

            if (! array_key_exists($field, $provider->driver->requiredFields())) {
                continue;
            }

            if (filled($nilai)) {
                $kredensial[$field] = trim($nilai);
            }
        }

        $provider->update([
            'name'        => $data['name'],
            'mode'        => $data['mode'],
            'fee_percent' => $data['fee_percent'] ?? 0,
            'fee_flat'    => $data['fee_flat'] ?? 0,
            'instruction' => $data['instruction'] ?? null,
            'credentials' => $kredensial,
        ]);

        app(ActivityLogger::class)->log('update', 'payment-provider', $provider);

        return back()->with('status', 'Provider diperbarui.');
    }

    public function enable(int $id): RedirectResponse
    {
        $provider = PaymentProvider::findOrFail($id);

        // Diperiksa dengan aturan yang sama persis dengan yang akan menolaknya
        // saat checkout. Mengaktifkan provider yang tidak siap hanya memindah
        // kegagalannya ke tempat yang lebih buruk.
        if ($kurang = $provider->missingFields()) {
            return back()->with('error',
                'Kredensial belum lengkap: '.implode(', ', $kurang).'.');
        }

        if (! $provider->driver->isImplemented()) {
            return back()->with('error',
                "Driver {$provider->driver->label()} masih kerangka dan belum bisa diaktifkan.");
        }

        $provider->update(['is_active' => true]);

        app(ActivityLogger::class)->log('enable', 'payment-provider', $provider);

        return back()->with('status', "Provider {$provider->name} diaktifkan.");
    }

    public function disable(int $id): RedirectResponse
    {
        $provider = PaymentProvider::findOrFail($id);

        // Default yang dimatikan meninggalkan sistem tanpa provider bawaan.
        // Ditolak di sini, bukan dibiarkan lalu ditemukan pengguna saat
        // checkout.
        if ($provider->is_default) {
            return back()->with('error',
                'Provider default tidak bisa dinonaktifkan. Tunjuk default lain dulu.');
        }

        $provider->update(['is_active' => false]);

        app(ActivityLogger::class)->log('disable', 'payment-provider', $provider);

        return back()->with('status', "Provider {$provider->name} dinonaktifkan.");
    }

    /**
     * Tepat satu default.
     *
     * Dijaga dengan transaction PLUS kunci seluruh baris, sama seperti
     * Storage Manager 7.2D. Transaction sendirian tidak cukup: dua permintaan
     * bersamaan bisa sama-sama membersihkan flag sebelum salah satunya commit,
     * dan hasilnya nol default.
     */
    public function makeDefault(int $id): RedirectResponse
    {
        $provider = PaymentProvider::findOrFail($id);

        if ($alasan = $provider->blocker()) {
            return back()->with('error', "Tidak bisa dijadikan default: {$alasan}");
        }

        DB::transaction(function () use ($provider) {

            PaymentProvider::query()->lockForUpdate()->get();

            PaymentProvider::query()->update(['is_default' => false]);

            $provider->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        app(ActivityLogger::class)->log('default', 'payment-provider', $provider);

        return back()->with('status', "{$provider->name} jadi metode pembayaran utama.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $provider = PaymentProvider::findOrFail($id);

        if ($provider->is_default) {
            return back()->with('error', 'Provider default tidak bisa dihapus.');
        }

        // Soft delete: transaksi lama menunjuk ke sini, dan riwayat pembayaran
        // yang kehilangan nama providernya tidak bisa ditelusuri saat ada
        // sengketa.
        $provider->delete();

        app(ActivityLogger::class)->log('delete', 'payment-provider', null, [
            'id'   => $id,
            'nama' => $provider->name,
        ]);

        return back()->with('status', "Provider {$provider->name} dihapus.");
    }

    private function uniqueSlug(string $nama): string
    {
        $dasar = Str::slug($nama) ?: 'provider';

        $slug = $dasar;

        $i = 2;

        while (PaymentProvider::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $dasar.'-'.$i++;
        }

        return $slug;
    }
}
