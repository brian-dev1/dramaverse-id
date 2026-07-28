<?php

namespace App\Repositories;

use App\Models\Drama;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Repositories\Contracts\DramaRepositoryInterface;

class DramaRepository implements DramaRepositoryInterface
{
    public function latest(int $limit = 12): Collection
    {
        return Drama::query()
            ->with([
                'country',
                'genres',
            ])
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function trending(int $limit = 12): Collection
    {
        return Drama::query()
            ->with([
                'country',
                'genres',
            ])
            ->where('is_trending', true)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function search(string $keyword): Collection
    {
        return Drama::query()
            ->with([
                'country',
                'genres',
            ])
            ->where(function ($query) use ($keyword) {

                $query->where(
                    'title',
                    'LIKE',
                    "%{$keyword}%"
                );

            })
            ->latest()
            ->limit(20)
            ->get();
    }

    public function findBySlug(string $slug): ?Drama
    {
        return Drama::query()
            ->with([
                'country',
                'genres',
                'episodes',
            ])
            ->where(
                'slug',
                $slug
            )
            ->first();
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Drama::query()
            ->with([
                'country',
                'genres',
            ])
            ->latest()
            ->paginate($perPage);
    }
}