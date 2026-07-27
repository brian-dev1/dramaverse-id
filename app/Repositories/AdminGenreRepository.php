<?php

namespace App\Repositories;

use App\Models\Genre;
use App\Repositories\Contracts\AdminGenreRepositoryInterface;

class AdminGenreRepository implements AdminGenreRepositoryInterface
{
    public function paginate()
    {
        return Genre::latest()->paginate(20);
    }

    public function store(array $data): Genre
    {
        return Genre::create($data);
    }

    public function update(
        Genre $genre,
        array $data
    ): Genre {

        $genre->update($data);

        return $genre;
    }

    public function delete(
        Genre $genre
    ): void {

        $genre->delete();

    }
}