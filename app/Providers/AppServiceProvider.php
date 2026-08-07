<?php

namespace App\Providers;

use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Contracts\AdminCountryRepositoryInterface;
use App\Repositories\Contracts\AdminDramaRepositoryInterface;
use App\Repositories\Contracts\AdminEpisodeRepositoryInterface;
use App\Repositories\Contracts\AdminGenreRepositoryInterface;
use App\Repositories\Contracts\AdminRepositoryInterface;
use App\Repositories\Contracts\BannerRepositoryInterface;
use App\Repositories\Contracts\DramaRepositoryInterface;
use App\Repositories\Contracts\EpisodeAccessRepositoryInterface;
use App\Repositories\Contracts\EpisodeRepositoryInterface;
use App\Repositories\Contracts\EpisodeSchedulerRepositoryInterface;
use App\Repositories\Contracts\FavoriteRepositoryInterface;
use App\Repositories\Contracts\HomeRepositoryInterface;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\ProgressRepositoryInterface;
use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Repositories\Contracts\StorageProviderRepositoryInterface;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Repositories\Contracts\WatchHistoryRepositoryInterface;

use App\Repositories\AnalyticsRepository;
use App\Repositories\AdminCountryRepository;
use App\Repositories\AdminDramaRepository;
use App\Repositories\AdminEpisodeRepository;
use App\Repositories\AdminGenreRepository;
use App\Repositories\AdminRepository;
use App\Repositories\BannerRepository;
use App\Repositories\DramaRepository;
use App\Repositories\EpisodeAccessRepository;
use App\Repositories\EpisodeRepository;
use App\Repositories\EpisodeSchedulerRepository;
use App\Repositories\FavoriteRepository;
use App\Repositories\HomeRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StorageProviderRepository;
use App\Repositories\TelegramRepository;
use App\Repositories\WatchHistoryRepository;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Services\Storage\StorageEngine;
use App\Services\Storage\StorageManager;
use App\Listeners\LogAuthenticationEvents;
use App\Models\EpisodeVideo;
use App\Observers\EpisodeVideoObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Telegram\Contracts\TelegramClientInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramService;
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
        AnalyticsRepositoryInterface::class => AnalyticsRepository::class,
        AdminCountryRepositoryInterface::class => AdminCountryRepository::class,
        AdminDramaRepositoryInterface::class => AdminDramaRepository::class,
        AdminEpisodeRepositoryInterface::class => AdminEpisodeRepository::class,
        AdminGenreRepositoryInterface::class => AdminGenreRepository::class,
        AdminRepositoryInterface::class => AdminRepository::class,
        BannerRepositoryInterface::class => BannerRepository::class,
        DramaRepositoryInterface::class => DramaRepository::class,
        EpisodeAccessRepositoryInterface::class => EpisodeAccessRepository::class,
        EpisodeRepositoryInterface::class => EpisodeRepository::class,
        EpisodeSchedulerRepositoryInterface::class => EpisodeSchedulerRepository::class,
        FavoriteRepositoryInterface::class => FavoriteRepository::class,
        HomeRepositoryInterface::class => HomeRepository::class,
        MembershipRepositoryInterface::class => MembershipRepository::class,
        NotificationRepositoryInterface::class => NotificationRepository::class,
        ProgressRepositoryInterface::class => ProgressRepository::class,
        SettingRepositoryInterface::class => SettingRepository::class,
        StorageProviderRepositoryInterface::class => StorageProviderRepository::class,
        TelegramRepositoryInterface::class => TelegramRepository::class,
        WatchHistoryRepositoryInterface::class => WatchHistoryRepository::class,
    ];

    public function register(): void
    {
        // StorageManager dipasang sebagai singleton karena ia memoisasi
        // instance disk yang sudah dibangun. Kalau tidak singleton,
        // memoisasinya tidak ada gunanya: setiap injeksi akan membangun
        // ulang klien S3 dari nol.
        $this->app->singleton(
            StorageManagerInterface::class,
            StorageManager::class
        );

        // Storage Engine: pintu masuk tunggal seluruh operasi berkas.
        // Singleton karena StorageManager yang dipakainya juga singleton —
        // instance disk yang sudah dibangun ikut terpakai ulang.
        $this->app->singleton(
            StorageEngineInterface::class,
            StorageEngine::class
        );

        /*
        |----------------------------------------------------------------------
        | Telegram
        |----------------------------------------------------------------------
        |
        | Client dan service dipasang terpisah supaya client bisa diganti
        | tiruan saat pengujian tanpa menyentuh service — pemisahan itulah
        | yang membuat lapisan ini bisa diuji tanpa jaringan sama sekali.
        |
        | Keduanya singleton karena tidak menyimpan keadaan per-permintaan.
        | withTimeout() dan withRetries() mengembalikan salinan, jadi
        | penyetelan sesaat tidak pernah bocor ke pemanggil berikutnya.
        |
        */

        $this->app->singleton(
            TelegramClientInterface::class,
            TelegramClient::class
        );

        $this->app->singleton(
            TelegramServiceInterface::class,
            TelegramService::class
        );

        /*
        |----------------------------------------------------------------------
        | Pembayaran
        |----------------------------------------------------------------------
        |
        | Singleton karena ia memoisasi instance gateway per driver. Kalau
        | tidak singleton, memoisasinya tidak ada gunanya.
        |
        | Perhatikan yang TIDAK di-bind: `PaymentGatewayInterface` sengaja
        | tidak punya binding tunggal. Gateway mana yang dipakai ditentukan
        | oleh baris `payment_providers`, bukan oleh container -- itulah yang
        | membuat dua provider dengan driver berbeda bisa hidup berdampingan.
        | Sebelum Phase 10 kontrak itu di-inject langsung tanpa pernah
        | di-bind, sehingga `PaymentService` lama tidak pernah bisa dibangun
        | sama sekali.
        |
        */
        $this->app->singleton(PaymentGatewayManager::class);
    }

    public function boot(): void
    {
        // MySQL < 5.7.7 membatasi panjang index string.
        Schema::defaultStringLength(191);

        /*
        |----------------------------------------------------------------------
        | Format waktu Indonesia
        |----------------------------------------------------------------------
        |
        | Nama bulan dan nama hari harus berbahasa Indonesia di SELURUH
        | aplikasi, bukan hanya di halaman yang kebetulan ingat memanggil
        | `translatedFormat`. Locale disetel sekali di sini.
        |
        | Macro-nya menempel di Carbon supaya blade dan pesan Telegram cukup
        | menulis `$invoice->due_at->lengkap()` — satu bentuk, satu tempat
        | mengubahnya. Sebelum ini ada tiga format berbeda untuk tanggal yang
        | sama, dan yang paling sering hilang justru jamnya.
        |
        */
        \Carbon\Carbon::setLocale('id');
        \Illuminate\Support\Carbon::setLocale('id');

        foreach (['lengkap', 'ringkas', 'presisi', 'relatif', 'lengkapRelatif'] as $bentuk) {
            \Illuminate\Support\Carbon::macro($bentuk, function () use ($bentuk) {
                /** @var \Illuminate\Support\Carbon $this */
                return \App\Support\Waktu::{$bentuk}($this);
            });
        }

        /*
        |----------------------------------------------------------------------
        | Observer video episode
        |----------------------------------------------------------------------
        |
        | Membuang cache Telegram dan — bila dinyalakan — mengantrekan
        | sinkronisasi otomatis.
        |
        | Observer, bukan panggilan di dalam service, karena ada TIGA jalur
        | yang membuat baris `episode_videos`: unggahan satuan (7.5), antrean
        | (7.7), dan Batch Upload (7.9). Menaruhnya di salah satunya berarti
        | dua jalur lain diam-diam tidak melakukannya — dan tidak ada yang
        | akan menyadarinya sampai ada video yang tidak pernah tersinkron.
        |
        */
        EpisodeVideo::observe(EpisodeVideoObserver::class);

        /*
        |----------------------------------------------------------------------
        | Jejak audit autentikasi
        |----------------------------------------------------------------------
        |
        | Masuk, keluar, percobaan gagal, dan terkunci. Empat-empatnya lewat
        | satu listener karena penulisannya identik dan hanya nama aksinya
        | yang berbeda.
        |
        */
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogAuthenticationEvents::class, 'handleLockout']);

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

        /*
        |----------------------------------------------------------------------
        | Pembayaran
        |----------------------------------------------------------------------
        |
        | Callback terbuka ke internet tanpa autentikasi apa pun dan tanpa
        | CSRF. Verifikasi tanda tangan di dalam driver adalah penjagaan
        | utamanya; batas ini menahan siapa pun yang mencoba menebak tanda
        | tangan dengan mengirim ribuan permintaan.
        |
        | Dibatasi per IP, dan angkanya dibaca dari config supaya bisa
        | dinaikkan tanpa deploy bila ada provider yang memang mengirim
        | callback lebih rapat daripada perkiraan.
        |
        */
        RateLimiter::for('payment-callback', fn (Request $request) =>
            Limit::perMinute((int) config('payment.guard.callback_rate', 60))->by($request->ip())
        );

        // Pembuatan tagihan. Batas jumlah tagihan menggantung di
        // CheckoutController adalah penjagaan kedua, bukan satu-satunya —
        // yang ini menahan lajunya, yang itu menahan jumlahnya.
        RateLimiter::for('checkout', fn (Request $request) =>
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
        );
    }
}
