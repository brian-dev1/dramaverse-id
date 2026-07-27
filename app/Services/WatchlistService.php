<?php

namespace App\Services;

use App\Models\User;
use App\Models\Drama;
use App\Repositories\Contracts\WatchlistRepositoryInterface;

class WatchlistService
{
    public function __construct(
        protected WatchlistRepositoryInterface $repository
    ){
    }

    public function all(User $user)
    {
        return $this->repository->all($user);
    }

    public function updateStatus(
        User $user,
        Drama $drama,
        string $status
    ){
        return $this->repository->updateStatus(
            $user,
            $drama,
            $status
        );
    }

    public function delete(
        User $user,
        Drama $drama
    ){
        return $this->repository->delete(
            $user,
            $drama
        );
    }
}