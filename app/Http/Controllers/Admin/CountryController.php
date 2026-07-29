<?php

namespace App\Http\Controllers\Admin;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CountryController extends AdminCrudController
{
    protected function model(): string
    {
        return Country::class;
    }

    protected function routeKey(): string
    {
        return 'country';
    }

    protected function label(): string
    {
        return 'Negara';
    }

    protected function columns(): array
    {
        return [
            'Nama'   => 'name',
            'Kode'   => 'code',
            'Slug'   => 'slug',
            'Drama'  => 'dramas_count',
            'Urutan' => 'sort_order',
            'Aktif'  => 'is_active',
        ];
    }

    protected function sortable(): array
    {
        return ['name', 'code', 'sort_order'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug', 'code'];
    }

    protected function withCount(): array
    {
        return ['dramas'];
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
            'slug'        => ['nullable', 'string', 'max:100', Rule::unique('countries', 'slug')->ignore($model?->getKey())],
            // Kode ISO dua huruf, dipakai sebagai penanda visual pengganti emoji bendera.
            'code'        => ['nullable', 'string', 'size:2', 'alpha'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'   => ['boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['slug']      = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : Str::slug($data['name']);
        $data['code']      = filled($data['code'] ?? null) ? Str::upper($data['code']) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    protected function bulkActions(): array
    {
        return [
            'activate'   => 'Aktifkan',
            'deactivate' => 'Nonaktifkan',
            'delete'     => 'Hapus terpilih',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        return match ($action) {
            'activate'   => $query->update(['is_active' => true]),
            'deactivate' => $query->update(['is_active' => false]),
            'delete'     => $query->delete(),
            default      => 0,
        };
    }
}
