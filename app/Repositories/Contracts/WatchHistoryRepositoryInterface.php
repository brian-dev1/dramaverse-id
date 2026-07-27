<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Episode;
use App\Models\WatchHistory;
use Illuminate\Support\Collection;

interface WatchHistoryRepositoryInterface
{
    public function save(
        User $user,
        Episode $episode
    ): WatchHistory;

    public function latest(
        User $user,
        int $limit = 10
    ): Collection;

    public function history(
        User $user
    ): Collection;

    public function find(
        User $user,
        Episode $episode
    ): ?WatchHistory;
}