<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Drama;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\RecommendationRepositoryInterface;

class RecommendationRepository implements RecommendationRepositoryInterface
{
    public function recommended(
        ?User $user = null,
        int $limit = 12
    ): Collection {

        $query = Drama::query();

        if ($user) {

            $genreIds = $user->watchHistories()

                ->join(
                    'dramas',
                    'watch_histories.drama_id',
                    '=',
                    'dramas.id'
                )

                ->pluck('genre_id')

                ->unique();

            if ($genreIds->isNotEmpty()) {

                $query->whereIn(
                    'genre_id',
                    $genreIds
                );

            }

        }

        return $query

            ->latest()

            ->take($limit)

            ->get();

    }

    public function becauseYouWatched(
        Drama $drama,
        int $limit = 12
    ): Collection {

        return Drama::query()

            ->where(
                'genre_id',
                $drama->genre_id
            )

            ->whereKeyNot(
                $drama->id
            )

            ->latest()

            ->take($limit)

            ->get();

    }

    public function trending(
        int $limit = 12
    ): Collection {

        return Drama::query()

            ->where(
                'is_trending',
                true
            )

            ->latest()

            ->take($limit)

            ->get();

    }
}