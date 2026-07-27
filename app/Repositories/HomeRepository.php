<?php

namespace App\Repositories;

use App\Models\Drama;
use App\Models\WatchHistory;
use App\Repositories\Contracts\HomeRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class HomeRepository implements HomeRepositoryInterface
{
    public function homeData(?int $userId = null): array
    {
        /*
        |--------------------------------------------------------------------------
        | Trending
        |--------------------------------------------------------------------------
        */

        $trending = Cache::remember(
            'homepage_trending',
            now()->addMinutes(5),
            fn() => Drama::query()
                ->with([
                    'genre',
                    'country',
                ])
                ->where('is_trending', true)
                ->latest()
                ->take(10)
                ->get()
        );

        /*
        |--------------------------------------------------------------------------
        | Latest Drama
        |--------------------------------------------------------------------------
        */

        $latest = Cache::remember(
            'homepage_latest',
            now()->addMinutes(5),
            fn() => Drama::query()
                ->with([
                    'genre',
                    'country',
                ])
                ->latest()
                ->take(12)
                ->get()
        );

        /*
        |--------------------------------------------------------------------------
        | Continue Watching
        |--------------------------------------------------------------------------
        */

        $continueWatching = collect();

        if ($userId) {
            $continueWatching = WatchHistory::query()
                ->with([
                    'drama.genre',
                    'drama.country',
                    'episode',
                ])
                ->where('user_id', $userId)
                ->where('completed', false)
                ->orderByDesc('last_watched_at')
                ->take(10)
                ->get();
        }

        return [
            'trending' => $trending,
            'latest' => $latest,
            'continueWatching' => $continueWatching,
        ];
    }
}