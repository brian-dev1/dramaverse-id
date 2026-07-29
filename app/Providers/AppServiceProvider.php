<?php

namespace App\Providers;

use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Repositories\Contracts\AdminCountryRepositoryInterface;
use App\Repositories\Contracts\AdminDramaRepositoryInterface;
use App\Repositories\Contracts\AdminEpisodeRepositoryInterface;
use App\Repositories\Contracts\AdminGenreRepositoryInterface;
use App\Repositories\Contracts\AdminRepositoryInterface;
use App\Repositories\Contracts\BannerRepositoryInterface;
use App\Repositories\Contracts\DramaCatalogRepositoryInterface;
use App\Repositories\Contracts\DramaRepositoryInterface;
use App\Repositories\Contracts\EpisodeAccessRepositoryInterface;
use App\Repositories\Contracts\EpisodeRepositoryInterface;
use App\Repositories\Contracts\EpisodeSchedulerRepositoryInterface;
use App\Repositories\Contracts\FavoriteRepositoryInterface;
use App\Repositories\Contracts\HomeRepositoryInterface;
use App\Repositories\Contracts\MediaRepositoryInterface;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\ProgressRepositoryInterface;
use App\Repositories\Contracts\RecommendationRepositoryInterface;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use App\Repositories\Contracts\SearchRepositoryInterface;
use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Repositories\Contracts\WatchHistoryRepositoryInterface;
use App\Repositories\Contracts\WatchlistRepositoryInterface;

use App\Repositories\ActivityLogRepository;
use App\Repositories\AdminCountryRepository;
use App\Repositories\AdminDramaRepository;
use App\Repositories\AdminEpisodeRepository;
use App\Repositories\AdminGenreRepository;
use App\Repositories\AdminRepository;
use App\Repositories\BannerRepository;
use App\Repositories\DramaCatalogRepository;
use App\Repositories\DramaRepository;
use App\Repositories\EpisodeAccessRepository;
use App\Repositories\EpisodeRepository;
use App\Repositories\EpisodeSchedulerRepository;
use App\Repositories\FavoriteRepository;
use App\Repositories\HomeRepository;
use App\Repositories\MediaRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TelegramRepository;
use App\Repositories\WatchHistoryRepository;
use App\Repositories\WatchlistRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Pemetaan interface repository ke implementasinya.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ActivityLogRepositoryInterface::class => ActivityLogRepository::class,
        AdminCountryRepositoryInterface::class => AdminCountryRepository::class,
        AdminDramaRepositoryInterface::class => AdminDramaRepository::class,
        AdminEpisodeRepositoryInterface::class => AdminEpisodeRepository::class,
        AdminGenreRepositoryInterface::class => AdminGenreRepository::class,
        AdminRepositoryInterface::class => AdminRepository::class,
        BannerRepositoryInterface::class => BannerRepository::class,
        DramaCatalogRepositoryInterface::class => DramaCatalogRepository::class,
        DramaRepositoryInterface::class => DramaRepository::class,
        EpisodeAccessRepositoryInterface::class => EpisodeAccessRepository::class,
        EpisodeRepositoryInterface::class => EpisodeRepository::class,
        EpisodeSchedulerRepositoryInterface::class => EpisodeSchedulerRepository::class,
        FavoriteRepositoryInterface::class => FavoriteRepository::class,
        HomeRepositoryInterface::class => HomeRepository::class,
        MediaRepositoryInterface::class => MediaRepository::class,
        MembershipRepositoryInterface::class => MembershipRepository::class,
        NotificationRepositoryInterface::class => NotificationRepository::class,
        ProgressRepositoryInterface::class => ProgressRepository::class,
        RecommendationRepositoryInterface::class => RecommendationRepository::class,
        ReviewRepositoryInterface::class => ReviewRepository::class,
        SearchRepositoryInterface::class => SearchRepository::class,
        SettingRepositoryInterface::class => SettingRepository::class,
        TelegramRepositoryInterface::class => TelegramRepository::class,
        WatchHistoryRepositoryInterface::class => WatchHistoryRepository::class,
        WatchlistRepositoryInterface::class => WatchlistRepository::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // MySQL < 5.7.7 membatasi panjang index string.
        Schema::defaultStringLength(191);

        // Pagination memakai markup Tailwind agar seragam dengan tema.
        Paginator::useTailwind();

        $this->registerRateLimiters();
    }

    /**
     * Pembatas laju.
     *
     * Login admin dibatasi per kombinasi email dan IP supaya penebakan
     * kata sandi tidak bisa dijalankan berulang, dan supaya satu penyerang
     * tidak bisa mengunci akun orang lain hanya dengan menebak dari IP
     * berbeda.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->input('email').'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Aksi tulis di panel: cukup longgar untuk kerja normal, cukup
        // ketat untuk menahan skrip otomatis.
        RateLimiter::for('admin-write', fn (Request $request) =>
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())
        );

        // Broadcast Telegram mahal — batasi keras.
        RateLimiter::for('broadcast', fn (Request $request) =>
            Limit::perHour(6)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('api', fn (Request $request) =>
            Limit::perMinute(90)->by($request->user()?->id ?: $request->ip())
        );
    }
}
