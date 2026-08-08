<?php

namespace App\Repositories;

use App\Models\Banner;
use App\Models\Country;
use App\Models\Drama;
use App\Models\Genre;
use App\Models\WatchHistory;
use App\Repositories\Contracts\HomeRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class HomeRepository implements HomeRepositoryInterface
{
    /** Umur cache untuk blok katalog homepage (menit). */
    private const CACHE_TTL = 5;

    /** Kolom yang dibutuhkan kartu drama — hindari SELECT *. */
    private const CARD_COLUMNS = [
        'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
        'release_year', 'total_episode', 'status', 'rating', 'views',
        'is_vip', 'published_at',
    ];

    /**
     * Kunci cache blok katalog beranda.
     *
     * Didaftarkan eksplisit, bukan lewat cache tag, karena store yang dipakai
     * di produksi adalah `database` — dan driver itu tidak mendukung tag.
     */
    public const CATALOG_KEYS = [
        'home:trending',
        'home:latest',
        'home:popular',
        'home:top-rated',
    ];

    /**
     * Membuang cache katalog beranda.
     *
     * Dipanggil DramaObserver setiap kali baris drama berubah. Tanpa ini,
     * drama yang baru diterbitkan baru muncul setelah TTL habis — dan admin
     * yang menyegarkan beranda lalu tidak melihat apa-apa akan menyimpulkan
     * penyimpanannya gagal.
     */
    public static function flushCatalog(): void
    {
        foreach (self::CATALOG_KEYS as $key) {
            Cache::forget($key);
        }
    }

    public function homeData(?int $userId = null): array
    {
        return [
            'banners'          => $this->banners(),
            'trending'         => $this->trending(),
            'latest'           => $this->latest(),
            'popular'          => $this->popular(),
            'topRated'         => $this->topRated(),
            'genres'           => $this->genres(),
            'countries'        => $this->countries(),
            'continueWatching' => $this->continueWatching($userId),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Hero
    |--------------------------------------------------------------------------
    */

    private function banners()
    {
        return Cache::remember(
            'home:banners',
            now()->addMinutes(self::CACHE_TTL),
            fn () => Banner::active('hero')->take(5)->get()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Blok katalog
    |--------------------------------------------------------------------------
    */

    private function trending()
    {
        return Cache::remember(
            'home:trending',
            now()->addMinutes(self::CACHE_TTL),
            fn () => $this->cardQuery()->trending()->take(10)->get()
        );
    }

    private function latest()
    {
        return Cache::remember(
            'home:latest',
            now()->addMinutes(self::CACHE_TTL),
            fn () => $this->cardQuery()->latestRelease()->take(12)->get()
        );
    }

    private function popular()
    {
        return Cache::remember(
            'home:popular',
            now()->addMinutes(self::CACHE_TTL),
            fn () => $this->cardQuery()->popular()->take(10)->get()
        );
    }

    private function topRated()
    {
        return Cache::remember(
            'home:top-rated',
            now()->addMinutes(self::CACHE_TTL),
            fn () => $this->cardQuery()->topRated()->take(12)->get()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Taksonomi
    |--------------------------------------------------------------------------
    */

    private function genres()
    {
        return Cache::remember(
            'home:genres',
            now()->addHour(),
            fn () => Genre::active()->take(12)->get()
        );
    }

    private function countries()
    {
        return Cache::remember(
            'home:countries',
            now()->addHour(),
            fn () => Country::active()->take(12)->get()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lanjutkan menonton (tidak di-cache — spesifik per pengguna)
    |--------------------------------------------------------------------------
    */

    private function continueWatching(?int $userId)
    {
        if ($userId === null) {
            return collect();
        }

        return WatchHistory::query()
            ->with([
                'drama:id,title,slug,poster,gradient,total_episode',
                'episode:id,drama_id,episode_number,title,duration',
            ])
            ->where('user_id', $userId)
            ->where('completed', false)
            ->whereHas('drama')
            ->whereHas('episode')
            ->orderByDesc('last_watched_at')
            ->take(10)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Query dasar kartu drama
    |--------------------------------------------------------------------------
    */

    private function cardQuery()
    {
        return Drama::query()
            ->select(self::CARD_COLUMNS)
            ->with([
                'country:id,name,slug,flag_emoji',
                'genres:id,name,slug',
            ])
            ->published();
    }
}
