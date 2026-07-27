<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Episode;
use App\Models\WatchHistory;

interface ProgressRepositoryInterface
{
    public function updateProgress(
        User $user,
        Episode $episode,
        int $progress
    ): WatchHistory;

    public function getProgress(
        User $user,
        Episode $episode
    ): ?WatchHistory;
}