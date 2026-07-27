<?php

namespace App\Services;

use App\Repositories\Contracts\DramaCatalogRepositoryInterface;

class DramaCatalogService
{
    public function __construct(
        protected DramaCatalogRepositoryInterface $repository
    ) {
    }

    public function paginate()
    {
        return $this->repository->paginate();
    }
}