<?php

namespace App\Services;

use App\Models\Episode;
use App\Repositories\Contracts\AdminEpisodeRepositoryInterface;

class AdminEpisodeService
{
    public function __construct(
        protected AdminEpisodeRepositoryInterface $repository
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
        Episode $episode,
        array $data
    ) {
        return $this->repository->update(
            $episode,
            $data
        );
    }

    public function delete(
        Episode $episode
    ) {
        $this->repository->delete($episode);
    }
}