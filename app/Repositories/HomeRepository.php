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

    /** Judul per halaman pada daftar "Rilis Terbaru" di dasar beranda. */
    private const CATALOG_PER_PAGE = 18;

    /** Kolom yang dibutuhkan kartu drama — hindari SELECT *. */
    private const CARD_COLUMNS = [
        'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
        'total_episode', 'status', 'views', 'is_vip', 'published_at',
    ];

    /**
     * Kunci cache blok katalog beranda.
     *
     * Didaftarkan eksplisit, bukan lewat cache tag, karena store yang dipakai
     * di produksi adalah `database` — dan driver itu tidak mendukung tag.
     */
    public const CATALOG_KEYS = [
        'home:trending',
        // 'home:latest' sengaja tidak ada di sini: daftar rilis terbaru
        // sekarang berhalaman dan tidak lagi di-cache sama sekali.
        'home:popular',
        'home:total-drama',
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
            'genres'           => $this->genres(),
            'countries'        => $this->countries(),
            'totalDrama'       => $this->totalDrama(),
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

    /**
     * Daftar rilis terbaru — berhalaman, bukan 12 teratas seperti dulu.
     *
     * Inilah yang menggantikan blok "Jelajahi Genre" dan "Jelajahi Negara"
     * di dasar beranda: pengunjung bisa terus maju ke halaman berikutnya
     * tanpa berpindah ke halaman katalog terpisah.
     *
     * TIDAK di-cache. Isinya berubah per nomor halaman, jadi kuncinya akan
     * beranak-pinak dan `flushCatalog()` tidak punya cara membuang semuanya —
     * drama yang baru terbit akan tersangkut di halaman yang kebetulan sudah
     * pernah dibuka orang.
     *
     * Yang mahal dari `paginate()` justru COUNT(*)-nya, dan itu dihindari
     * dengan mengoper total yang sudah di-cache sejam. Efek sampingnya
     * disengaja: nomor halaman dan angka "4.127 judul" di atas kepala halaman
     * selalu berasal dari bilangan yang sama, jadi keduanya tidak mungkin
     * saling bertentangan.
     */
    private function latest()
    {
        return $this->cardQuery()
            ->latestRelease()
            ->paginate(
                self::CATALOG_PER_PAGE,
                self::CARD_COLUMNS,
                'page',
                null,
                $this->totalDrama()
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

    /**
     * Jumlah drama terbit, untuk angka di bawah kolom cari.
     *
     * Di-cache satu jam, bukan lima menit seperti blok katalog. Angka ini
     * hanya berubah ketika ada judul baru diterbitkan, dan `flushCatalog()`
     * sudah membuangnya bersama yang lain setiap kali baris drama disimpan —
     * jadi TTL panjang tidak membuatnya basi, hanya membuat COUNT(*) tidak
     * dijalankan ulang untuk setiap pengunjung.
     */
    private function totalDrama(): int
    {
        return Cache::remember(
            'home:total-drama',
            now()->addHour(),
            fn () => Drama::query()->published()->count()
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
                'episode:id,drama_id,episode_number,title',
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
