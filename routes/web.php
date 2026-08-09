<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\Auth\TelegramMiniAppController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\Web;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DramaVerse ID — Web Routes
|--------------------------------------------------------------------------
|
| Konvensi penamaan:
|   web.*    → halaman publik & area pengguna
|   admin.*  → panel admin
|
| Setiap route di bawah ini memiliki controller dan view yang benar-benar ada.
|
*/

/*
|--------------------------------------------------------------------------
| Publik
|--------------------------------------------------------------------------
*/

Route::middleware('maintenance')->group(function () {

Route::get('/', Web\HomeController::class)->name('web.home');

// --- Pencarian ---
Route::controller(Web\WebSearchController::class)->group(function () {
    Route::get('/search', 'index')->name('web.search');
    Route::get('/search/result', 'result')->name('web.search.result');
});

// --- Katalog ---
Route::controller(Web\CatalogController::class)->group(function () {
    Route::get('/trending', 'trending')->name('web.trending');
    Route::get('/latest', 'latest')->name('web.latest');
    Route::get('/new-release', 'newRelease')->name('web.new-release');
    Route::get('/popular', 'popular')->name('web.popular');
    Route::get('/vip', 'vip')->name('web.vip');
});

// --- Taksonomi ---
Route::controller(Web\GenreController::class)->group(function () {
    Route::get('/genre', 'index')->name('web.genre.index');
    Route::get('/genre/{genre:slug}', 'show')->name('web.genre.show');
});

Route::controller(Web\CountryController::class)->group(function () {
    Route::get('/country', 'index')->name('web.country.index');
    Route::get('/country/{country:slug}', 'show')->name('web.country.show');
});

// --- Detail & pemutar ---
Route::get('/drama/{drama:slug}', Web\DramaController::class)->name('web.drama.show');
Route::get('/episode/{episode}', Web\EpisodeController::class)->name('web.episode.show');

// --- Membership (etalase, bisa dilihat tanpa login) ---
Route::get('/membership', [Web\MembershipController::class, 'index'])->name('web.membership');

// --- Halaman statis ---
Route::controller(Web\PageController::class)->group(function () {
    Route::get('/about', 'about')->name('web.about');
    Route::get('/help', 'help')->name('web.help');
    Route::get('/privacy', 'privacy')->name('web.privacy');
    Route::get('/terms', 'terms')->name('web.terms');
});

}); // akhir grup maintenance

/*
|--------------------------------------------------------------------------
| Autentikasi Telegram
|--------------------------------------------------------------------------
*/

Route::get('/auth/telegram/{token}', TelegramAuthController::class)->name('web.telegram.login');

// --- Telegram Mini App: tukar initData jadi sesi login ---
Route::post('/auth/telegram/miniapp', TelegramMiniAppController::class)
    ->middleware('throttle:20,1')
    ->name('web.telegram.miniapp');
/*
|--------------------------------------------------------------------------
| Callback pembayaran
|--------------------------------------------------------------------------
|
| Satu route untuk SEMUA provider; slug-nya menentukan yang mana. Menambah
| Stripe tidak menambah route.
|
| Dikecualikan dari CSRF di bootstrap/app.php -- provider tidak punya token
| kita. Yang menggantikannya: verifikasi tanda tangan di dalam driver, plus
| pembatas laju di bawah, karena endpoint ini terbuka ke internet tanpa
| autentikasi apa pun.
|
*/
Route::post('/payment/callback/{provider}', PaymentCallbackController::class)
    ->middleware('throttle:payment-callback')
    ->name('payment.callback');

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('telegram.webhook')
    ->name('telegram.webhook');

/*
|--------------------------------------------------------------------------
| Area Pengguna (wajib login Telegram)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {

    Route::get('/profile', [Web\ProfileController::class, 'index'])->name('web.profile');

    Route::get('/history', [Web\HistoryController::class, 'index'])->name('web.history');
    Route::get('/continue-watching', [Web\HistoryController::class, 'continueWatching'])
        ->name('web.continue-watching');

    Route::get('/favorites', [Web\FavoriteController::class, 'index'])->name('web.favorites');
    Route::post('/favorites/{drama:slug}', [Web\FavoriteController::class, 'toggle'])
        ->name('web.favorites.toggle');

    Route::get('/my-list', [Web\MyListController::class, 'index'])->name('web.my-list');
    Route::post('/my-list/{drama:slug}', [Web\MyListController::class, 'toggle'])
        ->name('web.my-list.toggle');

    Route::get('/notifications', [Web\NotificationController::class, 'index'])
        ->name('web.notifications');
    Route::post('/notifications/read-all', [Web\NotificationController::class, 'markAllRead'])
        ->name('web.notifications.read-all');

    /*
    |--------------------------------------------------------------------------
    | Program Affiliate
    |--------------------------------------------------------------------------
    |
    | `affiliate.stats` dipanggil berulang oleh halaman untuk pembaruan
    | langsung, jadi dibatasi lajunya sendiri — bukan oleh pembatas umum yang
    | dipakai aksi tulis.
    |
    */
    Route::get('/affiliate', [Web\AffiliateController::class, 'index'])->name('web.affiliate');
    Route::get('/affiliate/stats', [Web\AffiliateController::class, 'stats'])
        ->middleware('throttle:120,1')
        ->name('web.affiliate.stats');
    Route::post('/affiliate/withdraw', [Web\AffiliateController::class, 'withdraw'])
        ->middleware('throttle:10,1')
        ->name('web.affiliate.withdraw');

    Route::get('/settings', [Web\SettingController::class, 'index'])->name('web.settings');
    Route::put('/settings', [Web\SettingController::class, 'update'])->name('web.settings.update');

    /*
    |--------------------------------------------------------------------------
    | Checkout & tagihan
    |--------------------------------------------------------------------------
    |
    | `throttle:checkout` membatasi pembuatan tagihan. Tanpa itu, satu skrip
    | bisa membuat ribuan tagihan dalam semenit -- batas jumlah tagihan
    | menggantung di CheckoutController adalah penjagaan kedua, bukan
    | satu-satunya.
    |
    */

    Route::get('/invoice/{number}', [Web\CheckoutController::class, 'show'])
        ->name('web.invoice.show');

    Route::post('/invoice/{number}/cancel', [Web\CheckoutController::class, 'cancel'])
        ->name('web.invoice.cancel');

    Route::post('/logout', [Web\ProfileController::class, 'logout'])->name('web.logout');
});

/*
|--------------------------------------------------------------------------
| Panel Admin
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function () {

    // --- Login (tanpa middleware admin) ---
    Route::controller(Admin\AuthController::class)->group(function () {
        Route::get('/login', 'showLogin')->name('login');
        Route::post('/login', 'login')->name('login.attempt')->middleware('throttle:admin-login');
        Route::post('/logout', 'logout')->name('logout')->middleware('auth');
    });

    // --- Halaman terlindungi ---
    Route::middleware(['auth', 'admin', 'throttle:admin-write'])->group(function () {

        Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');

        /*
        |----------------------------------------------------------------------
        | CRUD
        |
        | Semua entitas memakai AdminCrudController, jadi pola rutenya sama.
        | Didaftarkan lewat perulangan agar tidak ada 40 baris berulang.
        |----------------------------------------------------------------------
        */
        $cruds = [
            'drama'   => Admin\DramaController::class,
            'episode' => Admin\EpisodeController::class,
            'genre'   => Admin\GenreController::class,
            'country' => Admin\CountryController::class,
            'banner'  => Admin\BannerController::class,
            'membership'   => Admin\MembershipController::class,
            'subscription'  => Admin\SubscriptionController::class,
            'role'          => Admin\RoleController::class,
            'admin-account' => Admin\AdminAccountController::class,
        ];

        // Izin yang diperlukan tiap modul CRUD.
        $crudPermissions = [
            'drama'        => 'drama.manage',
            'episode'      => 'episode.manage',
            'genre'        => 'taxonomy.manage',
            'country'      => 'taxonomy.manage',
            'banner'       => 'taxonomy.manage',
            'membership'   => 'membership.manage',
            'subscription'  => 'membership.manage',
            'role'          => 'role.manage',
            'admin-account' => 'admin.manage',
        ];

        foreach ($cruds as $key => $controller) {
            Route::controller($controller)
                ->prefix($key)
                ->name($key.'.')
                ->middleware('permission:'.$crudPermissions[$key])
                ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{id}/edit', 'edit')->name('edit')->whereNumber('id');
                Route::put('/{id}', 'update')->name('update')->whereNumber('id');
                Route::delete('/{id}', 'destroy')->name('destroy')->whereNumber('id');
                Route::post('/{id}/restore', 'restore')->name('restore')->whereNumber('id');
                Route::post('/bulk', 'bulk')->name('bulk');
            });
        }

        /*
        |----------------------------------------------------------------------
        | Daftar baca-saja (CRUD menyusul di bagian berikutnya)
        |----------------------------------------------------------------------
        */
        // --- Episode: tambah massal dan pengurutan ---
        Route::controller(Admin\EpisodeController::class)
            ->prefix('episode')->name('episode.')
            ->middleware('permission:episode.manage')
            ->group(function () {
                Route::get('/batch', 'batchForm')->name('batch');
                Route::post('/batch', 'batchStore')->name('batch.store');
                Route::post('/reorder', 'reorder')->name('reorder');
            });

        /*
        |----------------------------------------------------------------------
        | Drama: Asset Manager (Sprint 7.6)
        |
        | Poster, cover, banner, backdrop, logo, thumbnail trailer, galeri, dan
        | subtitle tingkat drama. Seluruh unggahan lewat StorageEngineInterface;
        | controller-nya tidak pernah menyentuh Storage.
        |
        | `store` dan `destroy` membalas JSON supaya halaman bekerja tanpa
        | memuat ulang — mengganti satu poster tidak seharusnya membuang
        | keadaan seluruh halaman, dan progress bar memerlukan XHR.
        |
        | Prefix `drama/{drama}/asset` tidak bertabrakan dengan CRUD drama:
        | route CRUD berbentuk `/{id}/edit` dan sejenisnya, dibatasi
        | whereNumber, sehingga pola di bawah tetap terpisah.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\DramaAssetController::class)
            ->prefix('drama/{drama}/asset')->name('drama.asset.')
            ->middleware('permission:drama.manage')
            ->whereNumber('drama')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::delete('/{asset}', 'destroy')->name('destroy')->whereNumber('asset');
            });

        /*
        |----------------------------------------------------------------------
        | Episode: unggah video (Sprint 7.5)
        |
        | Seluruh unggahan lewat StorageEngineInterface. Controller-nya tidak
        | pernah menyentuh Storage, tidak tahu driver apa pun, dan tidak
        | menyebut nama disk.
        |
        | `store` membalas JSON, bukan redirect. Itu keperluan progress bar:
        | satu-satunya cara mengetahui kemajuan pengiriman berkas gigabyte
        | adalah XMLHttpRequest.upload.onprogress, yang memerlukan respons
        | yang bisa dibaca JavaScript.
        |
        | Prefix `episode/video` tidak bertabrakan dengan CRUD episode:
        | route `/{id}/edit` dan sejenisnya dibatasi whereNumber, sehingga
        | "video" tidak akan pernah tertangkap sebagai id.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\EpisodeVideoController::class)
            ->prefix('episode/video')->name('episode.video.')
            ->middleware('permission:episode.manage')
            ->group(function () {
                Route::get('/', 'form')->name('form');
                Route::post('/', 'store')->name('store');
                Route::get('/episodes/{drama}', 'episodes')
                    ->name('episodes')->whereNumber('drama');
            });

        /*
        |----------------------------------------------------------------------
        | Batch Upload (Sprint 7.9)
        |
        | Banyak berkas sekali jalan, seluruhnya lewat antrean yang sama dengan
        | unggahan satuan. Tiap berkas dikirim sebagai permintaan tersendiri —
        | itulah cara progress per berkas dan "satu gagal, yang lain tetap
        | jalan" dijamin sekaligus. Alasan lengkapnya di
        | StoreBatchUploadRequest.
        |
        | Didaftarkan SEBELUM grup `upload/{uuid}` di bawah. Bukan keharusan —
        | `whereUuid()` sudah membuat "batch" tidak mungkin tertangkap sebagai
        | uuid — tetapi urutan ini membuat maksudnya terbaca tanpa perlu
        | memeriksa batasan route di bawahnya.
        |
        | Nama grupnya `batch.`, bukan `upload.batch.`, supaya menu sidebar
        | Upload Queue tidak ikut tersorot saat halaman ini dibuka: penanda
        | aktif di layout admin memakai `routeIs('admin.upload.*')`.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\BatchUploadController::class)
            ->prefix('upload/batch')->name('batch.')
            ->group(function () {

                Route::get('/', 'form')->name('form')
                    ->middleware('permission:upload.manage,episode.manage');

                Route::post('/', 'store')->name('store')
                    ->middleware('permission:upload.manage,episode.manage');

                // Status seluruh berkas satu batch dalam satu permintaan.
                // Dipanggil berkala selama batch berjalan; menanyakannya satu
                // per satu lewat admin.upload.show akan berarti dua puluh
                // permintaan setiap beberapa detik.
                Route::get('/{batch}/status', 'status')->name('status')
                    ->whereUuid('batch')
                    ->middleware('permission:upload.view,episode.manage');
            });

        /*
        |----------------------------------------------------------------------
        | Upload Queue (Sprint 7.7)
        |
        | Riwayat pekerjaan unggah beserta Retry, Cancel, dan Hapus.
        |
        | Parameternya UUID, bukan id berurut. Route `show` dipanggil berulang
        | kali sebagai polling dari halaman unggah, dan id berurut di sana
        | membocorkan jumlah unggahan seluruh sistem kepada siapa pun yang bisa
        | membuka satu halaman panel. `whereUuid()` sekaligus menjaga agar
        | bentuk yang salah ditolak router, bukan menjadi query ke database.
        |
        | Dua izin diterima seperti pola Storage Manager: `upload.*` baru ada
        | setelah RoleSeeder dijalankan ulang, sedangkan `episode.manage` sudah
        | dimiliki peran Editor sejak awal. Tanpa alternatif itu, halaman ini
        | akan tertutup untuk semua orang di server yang baru di-deploy.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\UploadQueueController::class)
            ->prefix('upload')->name('upload.')
            ->group(function () {

                Route::get('/', 'index')->name('index')
                    ->middleware('permission:upload.view,episode.manage');

                Route::get('/{uuid}', 'show')->name('show')->whereUuid('uuid')
                    ->middleware('permission:upload.view,episode.manage');

                Route::post('/{uuid}/retry', 'retry')->name('retry')->whereUuid('uuid')
                    ->middleware('permission:upload.manage,episode.manage');

                Route::post('/{uuid}/cancel', 'cancel')->name('cancel')->whereUuid('uuid')
                    ->middleware('permission:upload.manage,episode.manage');

                Route::delete('/{uuid}', 'destroy')->name('destroy')->whereUuid('uuid')
                    ->middleware('permission:upload.manage,episode.manage');
            });

        /*
        |----------------------------------------------------------------------
        | File Manager (Sprint 7.8)
        |
        | Satu daftar untuk seluruh berkas yang dikenal aplikasi, dibaca dari
        | `episode_videos` dan `drama_assets` sekaligus. Seluruh operasinya —
        | rename, move, delete, unduh — lewat StorageEngineInterface.
        |
        | Parameternya SEPASANG (`{source}/{id}`), bukan satu referensi
        | gabungan seperti `episode_video:12`. Titik dua di dalam segmen URL
        | selamat melewati router, tetapi `route()` meng-encode-nya menjadi
        | `%3A` dan hasilnya berbeda-beda antar proxy — bentuk dua segmen tidak
        | punya masalah itu sama sekali. `whereIn` menjaga agar sumber yang
        | tidak dikenal ditolak router, bukan menjadi query ke database.
        |
        | `show` membalas JSON: pratayang gambar dan tombol Salin URL
        | memerlukan URL bertanda tangan yang tidak boleh ikut dirender di
        | halaman daftar.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\FileManagerController::class)
            ->prefix('files')->name('files.')
            ->whereIn('source', ['episode_video', 'drama_asset'])
            ->whereNumber('id')
            ->group(function () {

                Route::get('/', 'index')->name('index')
                    ->middleware('permission:storage.view,setting.manage');

                Route::get('/{source}/{id}', 'show')->name('show')
                    ->middleware('permission:storage.view,setting.manage');

                Route::get('/{source}/{id}/download', 'download')->name('download')
                    ->middleware('permission:storage.view,setting.manage');

                Route::post('/{source}/{id}/rename', 'rename')->name('rename')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::post('/{source}/{id}/move', 'move')->name('move')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::delete('/{source}/{id}', 'destroy')->name('destroy')
                    ->middleware('permission:storage.manage,setting.manage');
            });

        // --- Pengguna: daftar, detail, dan tindakan ---
        Route::controller(Admin\UserController::class)
            ->prefix('user')->name('user.')
            ->middleware('permission:user.view,user.manage')
            ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{id}', 'show')->name('show')->whereNumber('id');
            Route::post('/{id}/ban', 'toggleBan')->name('ban')->whereNumber('id');
            Route::post('/{id}/active', 'toggleActive')->name('active')->whereNumber('id');
            Route::delete('/{id}', 'destroy')->name('destroy')->whereNumber('id');
            Route::post('/bulk', 'bulk')->name('bulk');
        });

        // --- Langganan: aksi tambahan di luar CRUD standar ---
        Route::post('/subscription/{id}/renew', [Admin\SubscriptionController::class, 'renew'])
            ->name('subscription.renew')->whereNumber('id');
        Route::post('/subscription/{id}/cancel', [Admin\SubscriptionController::class, 'cancel'])
            ->name('subscription.cancel')->whereNumber('id');

        // --- Telegram ---
        Route::middleware('permission:telegram.manage')->group(function () {
            Route::get('/telegram', [Admin\TelegramController::class, 'index'])->name('telegram');
            Route::post('/telegram/broadcast', [Admin\TelegramController::class, 'broadcast'])
                ->name('telegram.broadcast')->middleware('throttle:broadcast');

            // Menu tombol bot. Nama route-nya `admin.telegram-menu.*`, bukan
            // `admin.telegram.menu.*`, supaya penanda aktif menu Telegram di
            // sidebar tidak ikut menyala untuk halaman ini.
            Route::get('/telegram/menu', [Admin\TelegramMenuController::class, 'index'])
                ->name('telegram-menu.index');
            Route::put('/telegram/menu', [Admin\TelegramMenuController::class, 'update'])
                ->name('telegram-menu.update');
            Route::post('/telegram/menu', [Admin\TelegramMenuController::class, 'store'])
                ->name('telegram-menu.store');
            Route::post('/telegram/menu/reset', [Admin\TelegramMenuController::class, 'reset'])
                ->name('telegram-menu.reset');
            Route::delete('/telegram/menu/{id}', [Admin\TelegramMenuController::class, 'destroy'])
                ->name('telegram-menu.destroy')->whereNumber('id');

            // Sinkronisasi video episode ke Telegram. Berkasnya diambil dari
            // storage provider, bukan dari komputer siapa pun.
            Route::get('/telegram/sync', [Admin\TelegramSyncController::class, 'index'])
                ->name('telegram-sync.index');
            Route::post('/telegram/sync/all', [Admin\TelegramSyncController::class, 'syncAll'])
                ->name('telegram-sync.all');
            Route::post('/telegram/sync/{id}', [Admin\TelegramSyncController::class, 'sync'])
                ->name('telegram-sync.sync')->whereNumber('id');
            Route::post('/telegram/sync/{id}/retry', [Admin\TelegramSyncController::class, 'retry'])
                ->name('telegram-sync.retry')->whereNumber('id');

            // Lima aksi massal lewat satu endpoint — satu form, satu daftar
            // kotak centang. Lihat alasannya di TelegramSyncController::bulk().
            Route::post('/telegram/bulk', [Admin\TelegramSyncController::class, 'bulk'])
                ->name('telegram-sync.bulk');

            Route::get('/telegram/log', [Admin\TelegramLogController::class, 'index'])
                ->name('telegram-log.index');
        });

        /*
        |----------------------------------------------------------------------
        | Pembayaran (Phase 10)
        |
        | Memakai izin `membership.manage` yang sudah ada -- pembayaran dan
        | membership dikelola orang yang sama, dan izin baru berarti RoleSeeder
        | wajib dijalankan ulang di setiap pemasangan.
        |----------------------------------------------------------------------
        */
        Route::middleware('permission:membership.manage')->group(function () {

            Route::get('/payment/provider', [Admin\PaymentProviderController::class, 'index'])
                ->name('payment-provider.index');
            Route::post('/payment/provider', [Admin\PaymentProviderController::class, 'store'])
                ->name('payment-provider.store');
            Route::put('/payment/provider/{id}', [Admin\PaymentProviderController::class, 'update'])
                ->name('payment-provider.update')->whereNumber('id');
            Route::post('/payment/provider/{id}/enable', [Admin\PaymentProviderController::class, 'enable'])
                ->name('payment-provider.enable')->whereNumber('id');
            Route::post('/payment/provider/{id}/disable', [Admin\PaymentProviderController::class, 'disable'])
                ->name('payment-provider.disable')->whereNumber('id');
            Route::post('/payment/provider/{id}/default', [Admin\PaymentProviderController::class, 'makeDefault'])
                ->name('payment-provider.default')->whereNumber('id');
            Route::delete('/payment/provider/{id}', [Admin\PaymentProviderController::class, 'destroy'])
                ->name('payment-provider.destroy')->whereNumber('id');

            Route::get('/payment/invoice', [Admin\InvoiceController::class, 'index'])
                ->name('invoice.index');
            Route::get('/payment/invoice/export', [Admin\InvoiceController::class, 'export'])
                ->name('invoice.export');
            Route::get('/payment/invoice/{number}', [Admin\InvoiceController::class, 'show'])
                ->name('invoice.show');
            Route::post('/payment/invoice/{number}/cancel', [Admin\InvoiceController::class, 'cancel'])
                ->name('invoice.cancel');
            Route::post('/payment/transaction/{id}/verify', [Admin\InvoiceController::class, 'verify'])
                ->name('invoice.verify')->whereNumber('id');

            Route::get('/payment/log', [Admin\PaymentLogController::class, 'index'])
                ->name('payment-log.index');

            /*
            |------------------------------------------------------------------
            | Penarikan video premium
            |
            | Memakai izin `membership.manage` yang sama: yang berhak mencabut
            | akses seseorang adalah yang berhak menarik videonya. Izin baru
            | berarti RoleSeeder harus dijalankan ulang di produksi, dan admin
            | yang lupa menjalankannya akan menemukan halaman ini 403 tanpa
            | tahu sebabnya.
            |
            */
            Route::get('/telegram/retention', [Admin\TelegramRetentionController::class, 'index'])
                ->name('telegram-retention.index');
            Route::post('/telegram/retention/run', [Admin\TelegramRetentionController::class, 'runNow'])
                ->name('telegram-retention.run');
            Route::post('/telegram/retention/user/{user}', [Admin\TelegramRetentionController::class, 'purgeUser'])
                ->name('telegram-retention.user')->whereNumber('user');

            /*
            |------------------------------------------------------------------
            | ACC Manual
            |
            | Halaman terpisah dari daftar Tagihan dengan sengaja: yang satu
            | menjawab "apa yang terjadi di sistem pembayaran", yang ini
            | menjawab "orang ini bilang sudah bayar, benar tidak?" — dan
            | admin membuka yang kedua sambil memegang ID pengguna, bukan
            | nomor tagihan. Lihat docblock ManualApprovalController.
            |------------------------------------------------------------------
            */
            Route::get('/payment/acc', [Admin\ManualApprovalController::class, 'index'])
                ->name('manual-approval.index');
            Route::post('/payment/acc/{id}/approve', [Admin\ManualApprovalController::class, 'approve'])
                ->name('manual-approval.approve')->whereNumber('id');
            Route::post('/payment/acc/{id}/reject', [Admin\ManualApprovalController::class, 'rejectProof'])
                ->name('manual-approval.reject')->whereNumber('id');
        });

        Route::get('/logs', [Admin\LogController::class, 'index'])
            ->name('logs.index')->middleware('permission:log.view');

        /*
        |----------------------------------------------------------------------
        | Monitoring & Backup (Phase 9)
        |----------------------------------------------------------------------
        |
        | Memakai izin `setting.manage`, bukan izin baru. Yang boleh melihat
        | kesehatan sistem dan mengunduh cadangan adalah orang yang sama dengan
        | yang boleh mengubah pengaturan — dan menambah izin baru berarti
        | RoleSeeder harus dijalankan ulang di server sebelum halamannya bisa
        | dibuka sama sekali.
        |
        */
        Route::middleware('permission:setting.manage')->group(function () {

            Route::get('/monitoring', [Admin\MonitoringController::class, 'index'])
                ->name('monitoring.index');

            Route::post('/monitoring/backup', [Admin\MonitoringController::class, 'backupNow'])
                ->name('monitoring.backup');

            Route::post('/monitoring/backup/verify', [Admin\MonitoringController::class, 'verify'])
                ->name('monitoring.verify');

            Route::get('/monitoring/backup/download', [Admin\MonitoringController::class, 'download'])
                ->name('monitoring.download');

            Route::post('/monitoring/backup/prune', [Admin\MonitoringController::class, 'prune'])
                ->name('monitoring.prune');
        });

        Route::get('/system/log', [Admin\SystemLogController::class, 'index'])
            ->name('system-log.index')->middleware('permission:log.view');

        /*
        |----------------------------------------------------------------------
        | Storage Manager
        |
        | 7.2A: daftar baca-saja. 7.2B: tambah provider. 7.2C: ubah, hapus
        | (soft delete), pulihkan. 7.2D: enable, disable, set default,
        | update priority. 7.3: Test Connection.
        |
        | `bulk` sengaja BELUM didaftarkan. Itu bukan sekadar catatan:
        | crud/index.blade.php memeriksa Route::has() sebelum merender setiap
        | tombol, jadi selama route-nya tidak ada, tombolnya tidak pernah
        | muncul. Semua tombol di halaman ini muncul dengan sendirinya begitu
        | route-nya ada di bawah.
        |
        | Dua izin diterima di tiap baris. `storage.view` dan `storage.manage`
        | baru ditambahkan ke daftar izin, sehingga barisnya belum ada di
        | database sampai RoleSeeder dijalankan ulang. Tanpa `setting.manage`
        | sebagai alternatif, menu akan tersembunyi dan halamannya 403 di
        | server yang belum di-seed — gejala yang mudah disalahartikan
        | sebagai bug.
        |----------------------------------------------------------------------
        */
        /*
        |----------------------------------------------------------------------
        | Storage Monitoring (Sprint 7.8)
        |
        | Halaman pengamatan: jumlah provider, keadaan koneksi, jumlah dan
        | ukuran berkas, unggahan hari ini dan bulan ini.
        |
        | Didaftarkan SEBELUM grup `storage/{id}` di bawah. Sama seperti Batch
        | Upload, ini bukan keharusan — `{id}` sudah dibatasi whereNumber
        | sehingga "monitor" tidak mungkin tertangkap sebagai id — tetapi
        | urutannya membuat maksudnya terbaca langsung.
        |
        | `test` di sini POST dengan alasan yang sama seperti di Storage
        | Manager: Test Connection menulis lalu menghapus berkas uji di bucket,
        | dan sebagai GET ia bisa terpicu prefetch peramban.
        |----------------------------------------------------------------------
        */
        Route::controller(Admin\StorageMonitorController::class)
            ->prefix('storage/monitor')->name('storage-monitor.')
            ->group(function () {

                Route::get('/', 'index')->name('index')
                    ->middleware('permission:storage.view,setting.manage');

                // Membaca ulang database saja. TIDAK menghubungi provider mana
                // pun — menguji koneksi adalah tombol yang berbeda, dengan
                // biaya dan waktu tunggu yang berbeda pula.
                Route::get('/refresh', 'refresh')->name('refresh')
                    ->middleware('permission:storage.view,setting.manage');

                Route::post('/{id}/test', 'test')->name('test')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');
            });

        Route::controller(Admin\StorageController::class)
            ->prefix('storage')->name('storage.')
            ->group(function () {

                Route::get('/', 'index')->name('index')
                    ->middleware('permission:storage.view,setting.manage');

                Route::get('/create', 'create')->name('create')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::post('/', 'store')->name('store')
                    ->middleware('permission:storage.manage,setting.manage');

                // --- Sprint 7.2C ---
                Route::get('/{id}/edit', 'edit')->name('edit')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::put('/{id}', 'update')->name('update')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                // Soft delete: baris tidak hilang, hanya ditandai terhapus.
                Route::delete('/{id}', 'destroy')->name('destroy')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::post('/{id}/restore', 'restore')->name('restore')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                // --- Sprint 7.2D ---
                //
                // `priority` didaftarkan SEBELUM route ber-{id} tidak
                // diperlukan di sini karena prefix-nya berbeda bentuk
                // (/priority vs /{id}/...), dan {id} sudah dibatasi
                // whereNumber sehingga "priority" tidak akan pernah
                // tertangkap sebagai id.
                Route::post('/{id}/enable', 'enable')->name('enable')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::post('/{id}/disable', 'disable')->name('disable')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                Route::post('/{id}/default', 'makeDefault')->name('default')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');

                // Pembaruan massal: satu formulir mengirim prioritas seluruh
                // baris yang tampil, bukan satu permintaan per baris.
                Route::post('/priority', 'updatePriority')->name('priority')
                    ->middleware('permission:storage.manage,setting.manage');

                // --- Sprint 7.3 ---
                //
                // POST, bukan GET, walaupun terasa seperti "membaca": Test
                // Connection menulis lalu menghapus berkas uji di bucket.
                // Sebagai GET, ia bisa terpicu oleh prefetch peramban atau
                // perayap yang mengikuti tautan.
                //
                // Batas lajunya ikut `throttle:admin-write` (60/menit) yang
                // sudah dipasang di grup admin. Cukup: tiap penekanan tombol
                // memicu panggilan jaringan keluar dan satu tulis-hapus di
                // bucket, jadi ia memang tidak boleh bisa ditekan beruntun
                // tanpa batas.
                Route::post('/{id}/test', 'test')->name('test')->whereNumber('id')
                    ->middleware('permission:storage.manage,setting.manage');
            });

        Route::get('/analytics', [Admin\AnalyticsController::class, 'index'])
            ->name('analytics')->middleware('permission:report.view');

        Route::middleware('permission:report.view')->group(function () {
            Route::get('/report', [Admin\ReportController::class, 'index'])->name('report');
            Route::get('/report/print', [Admin\ReportController::class, 'print'])->name('report.print');
            Route::get('/report/export/{format}', [Admin\ReportController::class, 'export'])
                ->name('report.export')->whereIn('format', ['csv', 'xlsx']);
        });

        Route::middleware('permission:setting.manage')->group(function () {
            Route::get('/settings', [Admin\SettingController::class, 'index'])->name('settings');
            Route::put('/settings', [Admin\SettingController::class, 'update'])->name('settings.update');
        });

        /*
        |----------------------------------------------------------------------
        | Program Affiliate
        |----------------------------------------------------------------------
        |
        | Memakai izin `membership.manage` — orang yang sama yang mengurus
        | tagihan dan langganan. Izin baru tidak dipakai supaya modul ini
        | langsung terlihat setelah deploy tanpa menjalankan ulang RoleSeeder.
        |
        */
        Route::prefix('referral')->name('referral.')
            ->middleware('permission:membership.manage')
            ->controller(Admin\ReferralController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/settings', 'updateSettings')->name('settings');
                Route::put('/tiers', 'updateTiers')->name('tiers');
                Route::post('/commission/{id}/void', 'voidCommission')->name('commission.void')->whereNumber('id');
                Route::post('/commission/{id}/restore', 'restoreCommission')->name('commission.restore')->whereNumber('id');
                Route::post('/withdrawal/{id}', 'processWithdrawal')->name('withdrawal.process')->whereNumber('id');
            });

                /*
        |--------------------------------------------------------------------------
        | Video Inbox
        |
        | Video yang sudah diunggah worker Telegram ke storage provider.
        | Admin hanya memasangkan object yang sudah ada ke episode, sehingga
        | tidak ada download atau upload ulang berkas.
        |--------------------------------------------------------------------------
        */
        Route::controller(Admin\VideoInboxController::class)
            ->prefix('video-inbox')->name('video-inbox.')
            ->middleware('permission:episode.manage')
            ->group(function () {
                Route::get('/', 'index')->name('index');

                // Satu permintaan memasang banyak video sekaligus. Pasangan
                // video->episode dikirim sebagai array `pairs`.
                Route::post('/assign', 'assign')->name('assign');
            });
    });
});