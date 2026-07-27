<?php

namespace App\Services;

use App\Repositories\Contracts\ReviewRepositoryInterface;

class ReviewService
{
    public function __construct(
        protected ReviewRepositoryInterface $repository
    ){
    }

    public function create(array $data)
    {
        return $this->repository->create($data);
    }

    public function update($review,array $data)
    {
        return $this->repository->update($review,$data);
    }

    public function delete($review)
    {
        $this->repository->delete($review);
    }

    public function byDrama(int $id)
    {
        return $this->repository->byDrama($id);
    }

    public function average(int $id)
    {
        return $this->repository->average($id);
    }
}