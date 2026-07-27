<?php

namespace App\Services;

use App\Models\User;
use App\Models\Episode;
use App\Models\WatchHistory;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\WatchHistoryRepositoryInterface;

class WatchHistoryService
{
    public function __construct(
        protected WatchHistoryRepositoryInterface $repository
    ) {
    }

    /**
     * Simpan history.
     */
    public function save(
        User $user,
        Episode $episode
    ): WatchHistory {

        return $this->repository->save(
            $user,
            $episode
        );

    }

    /**
     * Update progress.
     */
    public function updateProgress(
        User $user,
        Episode $episode,
        int $progress
    ): WatchHistory {

        return $this->repository->updateProgress(
            $user,
            $episode,
            $progress
        );

    }

    /**
     * Complete episode.
     */
    public function complete(
        User $user,
        Episode $episode
    ): WatchHistory {

        return $this->repository->complete(
            $user,
            $episode
        );

    }

    /**
     * Continue Watching.
     */
    public function latest(
        User $user,
        int $limit = 10
    ): Collection {

        return $this->repository->latest(
            $user,
            $limit
        );

    }

    /**
     * Full History.
     */
    public function history(
        User $user,
        int $limit = 50
    ): Collection {

        return $this->repository->history(
            $user,
            $limit
        );

    }

    /**
     * History per drama.
     */
    public function byDrama(
        User $user,
        int $dramaId
    ): Collection {

        return $this->repository->byDrama(
            $user,
            $dramaId
        );

    }

    /**
     * Hapus satu history.
     */
    public function delete(
        User $user,
        Episode $episode
    ): bool {

        return $this->repository->delete(
            $user,
            $episode
        );

    }

    /**
     * Hapus semua history.
     */
    public function clear(
        User $user
    ): int {

        return $this->repository->clear(
            $user
        );

    }

    /**
     * Cari history.
     */
    public function find(
        User $user,
        Episode $episode
    ): ?WatchHistory {

        return $this->repository->find(
            $user,
            $episode
        );

    }
}