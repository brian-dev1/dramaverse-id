<?php

namespace App\Services;

use App\Models\Drama;
use App\Repositories\Contracts\AdminDramaRepositoryInterface;

class AdminDramaService
{
    public function __construct(
        protected AdminDramaRepositoryInterface $repository
    ) {
    }

    public function paginate()
    {
        return $this->repository->paginate();
    }

    public function store(array $data)
    {
        return $this->repository->store($data);
    }

    public function update(
        Drama $drama,
        array $data
    ) {
        return $this->repository->update(
            $drama,
            $data
        );
    }

    public function delete(
        Drama $drama
    ) {
        return $this->repository->delete(
            $drama
        );
    }
}