<?php

namespace App\Http\Controllers\Admin;

use App\Models\Genre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GenreController extends AdminCrudController
{
    protected function model(): string
    {
        return Genre::class;
    }

    protected function routeKey(): string
    {
        return 'genre';
    }

    protected function label(): string
    {
        return 'Genre';
    }

    protected function columns(): array
    {
        return [
            'Nama'   => 'name',
            'Slug'   => 'slug',
            'Ikon'   => 'icon',
            'Drama'  => 'dramas_count',
            'Urutan' => 'sort_order',
            'Aktif'  => 'is_active',
        ];
    }

    protected function sortable(): array
    {
        return ['name', 'slug', 'sort_order'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
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

    protected function formData(?Model $model = null): array
    {
        return [
            // Nama ikon yang tersedia di <x-web.home.icon>
            'icons' => ['star', 'play', 'trend', 'clock', 'home', 'user', 'bell', 'search', 'check'],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'slug'        => ['nullable', 'string', 'max:100', Rule::unique('genres', 'slug')->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:500'],
            'icon'        => ['nullable', 'string', 'max:32'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'   => ['boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['slug']      = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : Str::slug($data['name']);
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


    protected function withCount(): array
    {
        return ['dramas'];
    }
}
