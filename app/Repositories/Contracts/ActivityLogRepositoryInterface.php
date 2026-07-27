<?php

namespace App\Repositories\Contracts;

interface ActivityLogRepositoryInterface
{
    public function create(array $data);

    public function latest();

    public function byModule(string $module);

    public function byUser(int $userId);
}