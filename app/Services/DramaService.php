<?php

namespace App\Services;

use App\Models\Drama;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\DramaRepositoryInterface;

class DramaService
{
    public function __construct(
        protected DramaRepositoryInterface $repository
    ) {
    }

    /**
     * Homepage
     */
    public function homepage(): array
    {
        return [

            'trending' => $this->repository->trending(12),

            'latest' => $this->repository->latest(12),

        ];
    }

    /**
     * Search
     */
    public function search(string $keyword): Collection
    {
        return $this->repository->search($keyword);
    }

    /**
     * Detail
     */
    public function detail(string $slug): ?Drama
    {
        return $this->repository->findBySlug($slug);
    }
}