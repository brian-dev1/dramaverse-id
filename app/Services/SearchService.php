<?php

namespace App\Services;

use App\Repositories\Contracts\SearchRepositoryInterface;

class SearchService
{
    public function __construct(
        protected SearchRepositoryInterface $repository
    ) {
    }

    public function search(string $keyword): array
    {
        return $this->repository->search($keyword);
    }
}