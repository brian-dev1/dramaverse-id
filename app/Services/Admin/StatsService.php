<?php

namespace App\Services\Admin;

use App\Models\Country;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Menghitung angka untuk dashboard, analytics, dan laporan.
 *
 * Semua hasil di-cache singkat supaya membuka dashboard berulang kali
 * tidak memukul database. Angka nol dikembalikan apa adanya — tidak ada
 * nilai karangan saat data memang belum ada.
 */
class StatsService
{
    private const TTL = 300; // detik

    /*
    |--------------------------------------------------------------------------
    | Kartu ringkasan
    |--------------------------------------------------------------------------
    */

    public function summary(): array
    {
        return Cache::remember('admin:stats:summary', self::TTL, fn () => [
            'dramas'        => Drama::count(),
            'episodes'      => Episode::count(),
            'users'         => User::where('is_admin', false)->count(),
            'telegramUsers' => User::whereNotNull('telegram_id')->count(),
            'activeUsers'   => User::where('last_seen_at', '>=', now()->subDays(30))->count(),
            'vipMembers'    => $this->activeMembersOf('vip'),
            'premiumMembers'=> $this->activeMembersOf('premium'),
            'totalViews'    => (int) Drama::sum('views'),
            'watchToday'    => WatchHistory::whereDate('last_watched_at', today())->count(),
            'revenue'       => (float) Subscription::where('status', 'active')->sum('price'),
        ]);
    }

    /** Jumlah anggota aktif pada satu tier. */
    private function activeMembersOf(string $slug): int
    {
        return Subscription::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->whereHas('plan', fn ($q) => $q->where('slug', $slug))
            ->distinct('user_id')
            ->count('user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Deret waktu untuk grafik
    |--------------------------------------------------------------------------
    */

    /** Tontonan per hari selama N hari terakhir. */
    public function watchPerDay(int $days = 14): array
    {
        return Cache::remember("admin:stats:watch-day:{$days}", self::TTL, function () use ($days) {
            $rows = WatchHistory::query()
                ->selectRaw('DATE(last_watched_at) AS d, COUNT(*) AS total')
                ->whereNotNull('last_watched_at')
                ->where('last_watched_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->groupBy('d')
                ->pluck('total', 'd');

            return $this->fillDaily($rows, $days);
        });
    }

    /** Tontonan per bulan selama N bulan terakhir. */
    public function watchPerMonth(int $months = 12): array
    {
        return Cache::remember("admin:stats:watch-month:{$months}", self::TTL, function () use ($months) {
            $rows = WatchHistory::query()
                ->selectRaw("DATE_FORMAT(last_watched_at, '%Y-%m') AS m, COUNT(*) AS total")
                ->whereNotNull('last_watched_at')
                ->where('last_watched_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                ->groupBy('m')
                ->pluck('total', 'm');

            return $this->fillMonthly($rows, $months);
        });
    }

    /** Pengguna baru per hari. */
    public function userGrowth(int $days = 30): array
    {
        return Cache::remember("admin:stats:users:{$days}", self::TTL, function () use ($days) {
            $rows = User::query()
                ->selectRaw('DATE(created_at) AS d, COUNT(*) AS total')
                ->where('is_admin', false)
                ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->groupBy('d')
                ->pluck('total', 'd');

            return $this->fillDaily($rows, $days);
        });
    }

    /** Pendapatan per bulan. */
    public function revenuePerMonth(int $months = 12): array
    {
        return Cache::remember("admin:stats:revenue:{$months}", self::TTL, function () use ($months) {
            $rows = Subscription::query()
                ->selectRaw("DATE_FORMAT(started_at, '%Y-%m') AS m, SUM(price) AS total")
                ->whereNotNull('started_at')
                ->whereIn('status', ['active', 'expired'])
                ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                ->groupBy('m')
                ->pluck('total', 'm');

            return $this->fillMonthly($rows, $months);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Peringkat
    |--------------------------------------------------------------------------
    */

    public function topDramas(int $limit = 10): Collection
    {
        return Cache::remember("admin:stats:top-drama:{$limit}", self::TTL, fn () =>
            Drama::query()
                ->select(['id', 'title', 'slug', 'views', 'rating'])
                ->withCount('watchHistories')
                ->orderByDesc('views')
                ->take($limit)
                ->get()
        );
    }

    public function topGenres(int $limit = 8): Collection
    {
        return Cache::remember("admin:stats:top-genre:{$limit}", self::TTL, fn () =>
            Genre::query()
                ->select(['id', 'name', 'slug'])
                ->withCount('dramas')
                ->orderByDesc('dramas_count')
                ->take($limit)
                ->get()
        );
    }

    public function topCountries(int $limit = 8): Collection
    {
        return Cache::remember("admin:stats:top-country:{$limit}", self::TTL, fn () =>
            Country::query()
                ->select(['id', 'name', 'code'])
                ->withCount('dramas')
                ->orderByDesc('dramas_count')
                ->take($limit)
                ->get()
        );
    }

    public function mostActiveUsers(int $limit = 10): Collection
    {
        return Cache::remember("admin:stats:active-users:{$limit}", self::TTL, fn () =>
            User::query()
                ->select(['id', 'name', 'telegram_username', 'last_seen_at'])
                ->where('is_admin', false)
                ->withCount('watchHistories')
                ->orderByDesc('watch_histories_count')
                ->take($limit)
                ->get()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Melengkapi tanggal yang tidak punya data dengan nol, supaya grafik
     * tidak melompati hari kosong.
     *
     * @return array{labels: array<string>, values: array<int>}
     */
    private function fillDaily(Collection $rows, int $days): array
    {
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key  = $date->toDateString();

            $labels[] = $date->translatedFormat('d M');
            $values[] = (int) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @return array{labels: array<string>, values: array<float>} */
    private function fillMonthly(Collection $rows, int $months): array
    {
        $labels = [];
        $values = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key  = $date->format('Y-m');

            $labels[] = $date->translatedFormat('M Y');
            $values[] = (float) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Membuang seluruh cache statistik.
     *
     * Kuncinya didaftarkan eksplisit karena cache tag tidak didukung driver
     * `database` yang dipakai saat pengembangan.
     */
    public static function flush(): void
    {
        $keys = ['admin:stats:summary'];

        foreach ([7, 14, 30] as $n) {
            $keys[] = "admin:stats:watch-day:{$n}";
            $keys[] = "admin:stats:users:{$n}";
        }

        foreach ([6, 12] as $n) {
            $keys[] = "admin:stats:watch-month:{$n}";
            $keys[] = "admin:stats:revenue:{$n}";
        }

        foreach ([8, 10] as $n) {
            $keys[] = "admin:stats:top-drama:{$n}";
            $keys[] = "admin:stats:top-genre:{$n}";
            $keys[] = "admin:stats:top-country:{$n}";
            $keys[] = "admin:stats:active-users:{$n}";
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
