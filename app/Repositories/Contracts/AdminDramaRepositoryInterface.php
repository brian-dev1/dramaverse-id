<?php

namespace App\Repositories\Contracts;

use App\Models\Drama;

interface AdminDramaRepositoryInterface
{
    public function paginate();

    public function store(array $data): Drama;

    public function update(
        Drama $drama,
        array $data
    ): Drama;

    public function delete(
        Drama $drama
    ): void;
}