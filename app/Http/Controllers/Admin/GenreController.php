<?php

namespace App\Http\Controllers\Admin;

use App\Models\Genre;

class GenreController extends ResourceController
{
    protected function model(): string
    {
        return Genre::class;
    }

    protected function title(): string
    {
        return 'Kelola Genre';
    }

    protected function routeKey(): string
    {
        return 'genre';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Slug' => 'slug', 'Urutan' => 'sort_order', 'Aktif' => 'is_active'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    protected function relations(): array
    {
        return [];
    }
}
