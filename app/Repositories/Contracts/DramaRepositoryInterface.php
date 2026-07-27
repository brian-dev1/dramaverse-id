<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Models\Drama;

interface DramaRepositoryInterface
{
    public function latest(int $limit = 12): Collection;

    public function trending(int $limit = 12): Collection;

    public function search(string $keyword): Collection;

    public function findBySlug(string $slug): ?Drama;

    public function paginate(int $perPage = 20): LengthAwarePaginator;
}