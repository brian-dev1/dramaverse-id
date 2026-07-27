<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Drama;
use Illuminate\Support\Collection;

interface RecommendationRepositoryInterface
{
    /**
     * Rekomendasi untuk homepage.
     */
    public function recommended(
        ?User $user = null,
        int $limit = 12
    ): Collection;

    /**
     * Drama serupa.
     */
    public function becauseYouWatched(
        Drama $drama,
        int $limit = 12
    ): Collection;

    /**
     * Trending.
     */
    public function trending(
        int $limit = 12
    ): Collection;
}