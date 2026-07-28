<?php

namespace App\Repositories;

use App\Models\Drama;
use App\Repositories\Contracts\AdminDramaRepositoryInterface;

class AdminDramaRepository implements AdminDramaRepositoryInterface
{
    public function paginate()
    {
        return Drama::query()

            ->with([
                'genres',
                'country'
            ])

            ->latest()

            ->paginate(20);
    }

    public function store(array $data): Drama
    {
        return Drama::create($data);
    }

    public function update(
        Drama $drama,
        array $data
    ): Drama {

        $drama->update($data);

        return $drama;
    }

    public function delete(
        Drama $drama
    ): void {

        $drama->delete();

    }
}