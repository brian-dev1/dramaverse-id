<?php

namespace App\Repositories\Contracts;

use App\Models\Episode;

interface AdminEpisodeRepositoryInterface
{
    public function paginate();

    public function store(array $data): Episode;

    public function update(
        Episode $episode,
        array $data
    ): Episode;

    public function delete(
        Episode $episode
    ): void;
}