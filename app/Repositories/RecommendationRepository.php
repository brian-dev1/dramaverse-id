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

                ->join('drama_genre', 'dramas.id', '=', 'drama_genre.drama_id')

                ->pluck('drama_genre.genre_id')

                ->unique();

            if ($genreIds->isNotEmpty()) {

                $query->whereHas(
                    'genres',
                    fn ($q) => $q->whereIn('genres.id', $genreIds)
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

            ->whereHas(
                'genres',
                fn ($q) => $q->whereIn('genres.id', $drama->genres->pluck('id'))
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