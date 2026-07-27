<?php

namespace App\Repositories;

use App\Models\Episode;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Contracts\EpisodeRepositoryInterface;

class EpisodeRepository implements EpisodeRepositoryInterface
{
    public function find(int $id): ?Episode
    {
        return Episode::query()
            ->with([
                'drama',
            ])
            ->find($id);
    }

    public function detail(Episode $episode): Episode
    {
        return Episode::query()
            ->with([
                'drama.genre',
                'drama.country',
            ])
            ->findOrFail($episode->id);
    }

    public function byDrama(int $dramaId): Collection
    {
        return Episode::query()
            ->where('drama_id', $dramaId)
            ->orderBy('episode_number')
            ->get();
    }

    public function next(Episode $episode): ?Episode
    {
        return Episode::query()
            ->where('drama_id', $episode->drama_id)
            ->where('episode_number', '>', $episode->episode_number)
            ->orderBy('episode_number')
            ->first();
    }

    public function previous(Episode $episode): ?Episode
    {
        return Episode::query()
            ->where('drama_id', $episode->drama_id)
            ->where('episode_number', '<', $episode->episode_number)
            ->orderByDesc('episode_number')
            ->first();
    }
}