<?php

namespace App\Services;

use App\Models\Drama;
use App\Models\User;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\FavoriteRepositoryInterface;

class FavoriteService
{
    public function __construct(
        protected FavoriteRepositoryInterface $repository
    ) {
    }

    public function all(User $user): Collection
    {
        return $this->repository->all($user);
    }

    public function add(User $user, Drama $drama)
    {
        return $this->repository->add(
            $user,
            $drama
        );
    }

    public function remove(User $user, Drama $drama): bool
    {
        return $this->repository->remove(
            $user,
            $drama
        );
    }

    public function isFavorite(
        User $user,
        Drama $drama
    ): bool {
        return $this->repository->isFavorite(
            $user,
            $drama
        );
    }
}