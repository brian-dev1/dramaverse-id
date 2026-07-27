<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\User;

class PlayerService
{
    public function __construct(
        protected EpisodeService $episodeService,
        protected WatchHistoryService $watchHistoryService,
        protected ProgressService $progressService
    ) {
    }

    /**
     * Membuka halaman player.
     */
    public function watch(
        Episode $episode,
        ?User $user = null
    ): array
    {
        if ($user) {

            $this->watchHistoryService->save(
                $user,
                $episode
            );

        }

        $data = $this->episodeService->detail(
            $episode
        );

        $data['progress'] = 0;

        $data['completed'] = false;

        if ($user) {

            $history = $this->progressService->get(
                $user,
                $episode
            );

            if ($history) {

                $data['progress'] = $history->progress;

            }

            $watchHistory = $this->watchHistoryService->find(
                $user,
                $episode
            );

            if ($watchHistory) {

                $data['completed'] = $watchHistory->completed;

            }

        }

        return $data;
    }

    /**
     * Resume player.
     */
    public function resume(
        User $user,
        Episode $episode
    ): int
    {
        $history = $this->progressService->get(
            $user,
            $episode
        );

        return $history?->progress ?? 0;
    }

    /**
     * Tandai episode selesai.
     */
    public function complete(
        User $user,
        Episode $episode
    ): void
    {
        $this->watchHistoryService->complete(
            $user,
            $episode
        );
    }
}