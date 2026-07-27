<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Episode;
use App\Models\WatchHistory;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\WatchHistoryRepositoryInterface;

class WatchHistoryRepository implements WatchHistoryRepositoryInterface
{
    /**
     * Simpan history.
     */
    public function save(
        User $user,
        Episode $episode
    ): WatchHistory {

        return WatchHistory::updateOrCreate(

            [

                'user_id' => $user->id,

                'episode_id' => $episode->id,

            ],

            [

                'drama_id' => $episode->drama_id,

                'last_watched_at' => now(),

            ]

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

        return WatchHistory::updateOrCreate(

            [

                'user_id' => $user->id,

                'episode_id' => $episode->id,

            ],

            [

                'drama_id' => $episode->drama_id,

                'progress' => $progress,

                'last_watched_at' => now(),

            ]

        );

    }

    /**
     * Complete episode.
     */
    public function complete(
        User $user,
        Episode $episode
    ): WatchHistory {

        return WatchHistory::updateOrCreate(

            [

                'user_id' => $user->id,

                'episode_id' => $episode->id,

            ],

            [

                'drama_id' => $episode->drama_id,

                'progress' => 100,

                'completed' => true,

                'completed_at' => now(),

                'last_watched_at' => now(),

            ]

        );

    }

    /**
     * Continue Watching.
     */
    public function latest(
        User $user,
        int $limit = 10
    ): Collection {

        return WatchHistory::query()

            ->with([

                'drama',

                'episode',

            ])

            ->where(

                'user_id',

                $user->id

            )

            ->where(

                'completed',

                false

            )

            ->latest(

                'last_watched_at'

            )

            ->limit($limit)

            ->get();

    }

    /**
     * Full History.
     */
    public function history(
        User $user,
        int $limit = 50
    ): Collection {

        return WatchHistory::query()

            ->with([

                'drama',

                'episode',

            ])

            ->where(

                'user_id',

                $user->id

            )

            ->latest(

                'last_watched_at'

            )

            ->limit($limit)

            ->get();

    }

    /**
     * Riwayat drama tertentu.
     */
    public function byDrama(
        User $user,
        int $dramaId
    ): Collection {

        return WatchHistory::query()

            ->with([

                'episode',

            ])

            ->where(

                'user_id',

                $user->id

            )

            ->where(

                'drama_id',

                $dramaId

            )

            ->latest(

                'last_watched_at'

            )

            ->get();

    }

    /**
     * Hapus history episode tertentu.
     */
    public function delete(
        User $user,
        Episode $episode
    ): bool {

        return WatchHistory::query()

            ->where(

                'user_id',

                $user->id

            )

            ->where(

                'episode_id',

                $episode->id

            )

            ->delete() > 0;

    }

    /**
     * Hapus seluruh history user.
     */
    public function clear(
        User $user
    ): int {

        return WatchHistory::query()

            ->where(

                'user_id',

                $user->id

            )

            ->delete();

    }

    /**
     * Find.
     */
    public function find(
        User $user,
        Episode $episode
    ): ?WatchHistory {

        return WatchHistory::query()

            ->where(

                'user_id',

                $user->id

            )

            ->where(

                'episode_id',

                $episode->id

            )

            ->first();

    }
}