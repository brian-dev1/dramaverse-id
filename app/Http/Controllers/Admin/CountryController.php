<?php

namespace App\Http\Controllers\Admin;

use App\Models\Country;

class CountryController extends ResourceController
{
    protected function model(): string
    {
        return Country::class;
    }

    protected function title(): string
    {
        return 'Kelola Negara';
    }

    protected function routeKey(): string
    {
        return 'country';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Kode' => 'code', 'Bendera' => 'flag_emoji', 'Aktif' => 'is_active'];
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
