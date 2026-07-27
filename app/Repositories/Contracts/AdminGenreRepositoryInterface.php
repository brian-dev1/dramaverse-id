<?php

namespace App\Repositories\Contracts;

use App\Models\Genre;

interface AdminGenreRepositoryInterface
{
    public function paginate();

    public function store(array $data): Genre;

    public function update(
        Genre $genre,
        array $data
    ): Genre;

    public function delete(
        Genre $genre
    ): void;
}