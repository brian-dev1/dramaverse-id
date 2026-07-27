<?php

namespace App\Repositories\Contracts;

interface HomeRepositoryInterface
{
    public function homeData(?int $userId = null): array;
}