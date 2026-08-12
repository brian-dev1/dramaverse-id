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

    /**
     * Cari drama berdasarkan judul.
     *
     * Kata kunci dipotong dan wildcard-nya diloloskan dengan alasan yang sama
     * seperti di WebSearchRepository: `%` yang dibiarkan apa adanya membuat
     * pencarian satu karakter cocok dengan seluruh tabel, dan pola sepanjang
     * ribuan karakter membuat setiap baris dibandingkan dengan harga penuh.
     * Keduanya bisa dipicu tanpa login.
     */
    public function search(string $keyword): Collection
    {
        $pola = '%'.str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_substr(trim($keyword), 0, 100)
        ).'%';

        return Drama::query()
            ->with([
                'country',
                'genres',
            ])
            ->where(function ($query) use ($pola) {

                $query->where(
                    'title',
                    'LIKE',
                    $pola
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