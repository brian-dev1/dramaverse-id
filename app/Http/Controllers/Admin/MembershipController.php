<?php

namespace App\Http\Controllers\Admin;

use App\Models\MembershipPlan;
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
            'Harga'         => 'price',
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
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'slug'        => ['nullable', 'string', 'max:50', 'alpha_dash',
                              Rule::unique('membership_plans', 'slug')->ignore($model?->getKey())],
            'price'       => ['required', 'numeric', 'min:0', 'max:99999999'],
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

        $data['is_active'] = $request->boolean('is_active');

        // Benefit diisi satu per baris di textarea, disimpan sebagai JSON.
        $data['benefits'] = collect(preg_split('/\r\n|\r|\n/', (string) ($data['benefits'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all() ?: null;

        return $data;
    }

    protected function formData(?Model $model = null): array
    {
        return [
            // Textarea menampilkan benefit satu per baris.
            'benefitsText' => $model?->benefits ? implode("\n", $model->benefits) : '',
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
