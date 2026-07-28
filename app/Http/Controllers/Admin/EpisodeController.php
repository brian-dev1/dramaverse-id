<?php

namespace App\Http\Controllers\Admin;

use App\Models\Episode;

class EpisodeController extends ResourceController
{
    protected function model(): string
    {
        return Episode::class;
    }

    protected function title(): string
    {
        return 'Kelola Episode';
    }

    protected function routeKey(): string
    {
        return 'episode';
    }

    protected function columns(): array
    {
        return ['Drama' => 'drama.title', 'Episode' => 'episode_number', 'Judul' => 'title', 'Durasi' => 'duration', 'Status' => 'status'];
    }

    protected function searchable(): array
    {
        return ['title'];
    }

    protected function relations(): array
    {
        return ['drama:id,title'];
    }
}
