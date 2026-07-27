<?php

namespace App\Repositories\Contracts;

interface ReviewRepositoryInterface
{
    public function create(array $data);

    public function update($review,array $data);

    public function delete($review);

    public function byDrama(int $dramaId);

    public function average(int $dramaId);
}