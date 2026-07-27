<?php

namespace App\Services;

use App\Models\Episode;
use App\Repositories\Contracts\EpisodeRepositoryInterface;

class EpisodeService
{
    public function __construct(
        protected EpisodeRepositoryInterface $repository
    ) {
    }

    public function detail(Episode $episode): array
    {
        return [

            'episode' => $this->repository->detail($episode),

            'previousEpisode' => $this->repository->previous($episode),

            'nextEpisode' => $this->repository->next($episode),

            'episodes' => $this->repository->byDrama($episode->drama_id),

        ];
    }
}