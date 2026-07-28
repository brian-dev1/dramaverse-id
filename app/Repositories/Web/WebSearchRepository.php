<?php

namespace App\Repositories\Web;

use App\Models\Drama;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WebSearchRepository
{
    private const PER_PAGE = 24;

    /**
     * Pencarian drama dengan filter genre, negara, tahun, VIP, dan status.
     *
     * Menerima parameter `q` (dipakai UI) maupun `keyword` (kompatibilitas lama).
     */
    public function search(Request $request): LengthAwarePaginator
    {
        $keyword = trim((string) ($request->get('q') ?? $request->get('keyword') ?? ''));

        $query = Drama::query()
            ->select([
                'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views',
                'is_vip', 'published_at',
            ])
            ->with([
                'country:id,name,slug,flag_emoji',
                'genres:id,name,slug',
            ])
            ->published();

        // --- Kata kunci ---
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('original_title', 'like', "%{$keyword}%");
            });
        }

        // --- Genre ---
        if ($request->filled('genre')) {
            $query->whereHas(
                'genres',
                fn (Builder $q) => $q->where('genres.slug', $request->string('genre'))
            );
        }

        // --- Negara ---
        if ($request->filled('country')) {
            $query->whereHas(
                'country',
                fn (Builder $q) => $q->where('countries.slug', $request->string('country'))
            );
        }

        // --- Tahun rilis ---
        if ($request->filled('year')) {
            $query->where('release_year', $request->integer('year'));
        }

        // --- Status tayang ---
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // --- Khusus VIP ---
        if ($request->boolean('vip')) {
            $query->where('is_vip', true);
        }

        // --- Urutan ---
        match ($request->get('sort')) {
            'rating'  => $query->orderByDesc('rating'),
            'popular' => $query->orderByDesc('views'),
            'oldest'  => $query->orderBy('published_at'),
            default   => $query->orderByDesc('published_at'),
        };

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }
}
