<?php

namespace App\Http\Controllers\Admin;

use App\Models\Banner;

class BannerController extends ResourceController
{
    protected function model(): string
    {
        return Banner::class;
    }

    protected function title(): string
    {
        return 'Kelola Banner';
    }

    protected function routeKey(): string
    {
        return 'banner';
    }

    protected function columns(): array
    {
        return ['Judul' => 'title', 'Posisi' => 'position', 'Urutan' => 'sort_order', 'Aktif' => 'is_active'];
    }

    protected function searchable(): array
    {
        return ['title'];
    }

    protected function relations(): array
    {
        return [];
    }
}
