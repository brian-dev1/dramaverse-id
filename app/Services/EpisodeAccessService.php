<?php

namespace App\Services;

use App\Models\User;
use App\Models\Episode;
use App\Repositories\Contracts\EpisodeAccessRepositoryInterface;

class EpisodeAccessService
{
    public function __construct(
        protected EpisodeAccessRepositoryInterface $repository
    ) {
    }

    public function canWatch(
        ?User $user,
        Episode $episode
    ): bool
    {
        return $this->repository->canWatch(
            $user,
            $episode
        );
    }
}