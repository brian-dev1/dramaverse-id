<?php

namespace App\Repositories;

use App\Models\Episode;
use App\Repositories\Contracts\AdminEpisodeRepositoryInterface;

class AdminEpisodeRepository implements AdminEpisodeRepositoryInterface
{
    public function paginate()
    {
        return Episode::query()
            ->with('drama')
            ->latest()
            ->paginate(20);
    }

    public function store(array $data): Episode
    {
        return Episode::create($data);
    }

    public function update(
        Episode $episode,
        array $data
    ): Episode {

        $episode->update($data);

        return $episode;
    }

    public function delete(
        Episode $episode
    ): void {

        $episode->delete();

    }
}