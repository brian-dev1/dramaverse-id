<?php

namespace App\Services;

use App\Repositories\Contracts\AdminRepositoryInterface;

class AdminService
{
    public function __construct(
        protected AdminRepositoryInterface $repository
    ) {
    }

    public function dashboard()
    {
        return $this->repository->dashboard();
    }
}