<?php

namespace App\Services;

use App\Repositories\Contracts\HomeRepositoryInterface;

class HomeService
{
    public function __construct(
        protected HomeRepositoryInterface $repository
    ) {
    }

    public function home(?int $userId = null): array
    {
        return $this->repository->homeData($userId);
    }
}