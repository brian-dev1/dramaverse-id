<?php

namespace App\Repositories;

use App\Models\Episode;
use App\Models\User;
use App\Models\WatchHistory;
use App\Repositories\Contracts\ProgressRepositoryInterface;

class ProgressRepository implements ProgressRepositoryInterface
{
    public function updateProgress(
        User $user,
        Episode $episode,
        int $progress
    ): WatchHistory {

        $progress = max(0, min(100, $progress));

        return WatchHistory::updateOrCreate(
            [
                'user_id' => $user->id,
                'episode_id' => $episode->id,
            ],
            [
                'drama_id' => $episode->drama_id,
                'progress' => $progress,
                'completed' => $progress >= 100,
                'completed_at' => $progress >= 100 ? now() : null,
                'last_watched_at' => now(),
            ]
        );
    }

    public function getProgress(
        User $user,
        Episode $episode
    ): ?WatchHistory {

        return WatchHistory::query()
            ->with([
                'episode',
                'drama',
            ])
            ->where('user_id', $user->id)
            ->where('episode_id', $episode->id)
            ->first();
    }
}