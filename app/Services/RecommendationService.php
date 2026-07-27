<?php

namespace App\Services;

use App\Models\User;
use App\Models\Drama;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\RecommendationRepositoryInterface;

class RecommendationService
{
    public function __construct(
        protected RecommendationRepositoryInterface $repository
    ) {
    }

    public function recommended(
        ?User $user = null
    ): Collection {

        return $this->repository->recommended(
            $user
        );

    }

    public function becauseYouWatched(
        Drama $drama
    ): Collection {

        return $this->repository->becauseYouWatched(
            $drama
        );

    }

    public function trending(): Collection
    {
        return $this->repository->trending();
    }
}