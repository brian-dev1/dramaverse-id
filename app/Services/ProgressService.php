<?php

namespace App\Services;

use App\Models\User;
use App\Models\Episode;
use App\Repositories\Contracts\ProgressRepositoryInterface;

class ProgressService
{
    public function __construct(
        protected ProgressRepositoryInterface $repository
    ) {
    }

    public function update(
        User $user,
        Episode $episode,
        int $progress
    ) {
        return $this->repository->updateProgress(
            $user,
            $episode,
            $progress
        );
    }

    public function get(
        User $user,
        Episode $episode
    ) {
        return $this->repository->getProgress(
            $user,
            $episode
        );
    }
}