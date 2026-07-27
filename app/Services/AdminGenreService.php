<?php

namespace App\Services;

use App\Models\Genre;
use App\Repositories\Contracts\AdminGenreRepositoryInterface;

class AdminGenreService
{
    public function __construct(
        protected AdminGenreRepositoryInterface $repository
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
        Genre $genre,
        array $data
    ) {
        return $this->repository->update(
            $genre,
            $data
        );
    }

    public function delete(
        Genre $genre
    ) {
        $this->repository->delete($genre);
    }
}