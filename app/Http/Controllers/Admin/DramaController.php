<?php

namespace App\Http\Controllers\Admin;

use App\Models\Drama;

class DramaController extends ResourceController
{
    protected function model(): string
    {
        return Drama::class;
    }

    protected function title(): string
    {
        return 'Kelola Drama';
    }

    protected function routeKey(): string
    {
        return 'drama';
    }

    protected function columns(): array
    {
        return ['Judul' => 'title', 'Negara' => 'country.name', 'Tahun' => 'release_year', 'Status' => 'status', 'Rating' => 'rating'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function relations(): array
    {
        return ['country:id,name'];
    }
}
