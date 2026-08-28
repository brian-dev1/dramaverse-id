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

/*
| `throttle:publik` menutup lubang yang paling lebar di situs ini. Seluruh
| halaman di grup ini bisa dibuka tanpa akun, tanpa token, dan tanpa satu pun
| syarat — dan sebelum ini juga tanpa batas laju. Sebuah skrip yang mengulang
| GET ke `/trending` menghabiskan worker php-fpm dalam hitungan detik, dan
| ketika worker habis seluruh situs ikut mati: pembayaran, admin, webhook
| Telegram. Batas di halaman lain tidak menolong bila antreannya sudah penuh
| lebih dulu.
|
| Dipasang di grup, bukan per route, supaya halaman publik yang ditambahkan
| nanti ikut terlindungi tanpa harus diingat.
*/
Route::middleware(['maintenance', 'throttle:publik'])->group(function () {

Route::get('/', Web\HomeController::class)->name('web.home');

/*
| Pencarian dapat batasnya sendiri yang lebih ketat. `LIKE '%kata%'` tidak
| bisa memakai indeks, jadi satu permintaan pencarian memindai seluruh tabel
| drama — jauh lebih mahal daripada halaman katalog di sebelahnya. Menyamakan
| batasnya berarti membayar harga termahal dengan jatah termurah.
*/
Route::controller(Web\WebSearchController::class)
    ->middleware('throttle:pencarian')
    ->group(function () {
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
Route::get('/part/{episode}', Web\EpisodeController::class)->name('web.episode.show');

/*
| Alamat lama /episode/12.
|
| Namanya berganti jadi "part" di seluruh tampilan, tapi tautan lamanya sudah
| tersebar: tersimpan di riwayat chat orang, di postingan channel yang sudah
| terbit, dan di tab yang belum ditutup. Mematikannya berarti mereka semua
| mendarat di halaman 404 tanpa satu pun petunjuk ke mana harus pergi.
|
| 301, bukan 302: pengalihannya permanen, dan status itulah yang membuat
| mesin pencari dan klien Telegram memperbarui catatannya sendiri alih-alih
| bertanya lagi setiap kali.
|
| Nama rutenya tetap `web.episode.show`. Nama rute tidak pernah dilihat
| pengguna, sementara menggantinya berarti menyentuh puluhan pemanggilan
| `route()` di view dan handler bot demi perubahan yang tak terlihat siapa
| pun — dan satu yang terlewat baru ketahuan sebagai halaman error.
*/
Route::permanentRedirect('/episode/{episode}', '/part/{episode}');

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
    | Permintaan drama
    |--------------------------------------------------------------------------
    |
    | Butuh login dengan sengaja. Permintaan tanpa pemilik tidak ada gunanya:
    | fitur ini janjinya adalah "Anda akan diberi tahu saat dramanya ada", dan
    | janji itu tidak bisa ditepati kepada orang yang tidak dikenali.
    |
    | Pengiriman dibatasi lajunya sendiri. Kolom teks bebas yang bisa dikirim
    | tanpa batas adalah kolom yang suatu saat dipakai membanjiri panel admin.
    |
    */
    Route::get('/request', [Web\DramaRequestController::class, 'index'])
        ->name('web.request.index');

    Route::post('/request', [Web\DramaRequestController::class, 'store'])
        ->name('web.request.store')
        ->middleware('throttle:drama-request');

    Route::delete('/request/{id}', [Web\DramaRequestController::class, 'destroy'])
        ->name('web.request.destroy')->whereNumber('id');

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
        | Pratinjau halaman galat
        |----------------------------------------------------------------------
        |
        | Halaman galat satu-satunya bagian situs yang tidak bisa diperiksa
        | tanpa merusak sesuatu lebih dulu. Route ini membukanya langsung:
        |
        |   /admin/pratinjau-galat/419   /admin/pratinjau-galat/500
        |   /admin/pratinjau-galat/404   /admin/pratinjau-galat/403
        |
        | Berlaku juga di produksi — sengaja, karena pengembangan situs ini
        | dilakukan langsung di server dan halaman yang tidak pernah bisa
        | dilihat adalah halaman yang tidak pernah diperbaiki.
        |
        | Yang membuatnya aman bukan environment, melainkan siapa yang boleh
        | membukanya: sudah lolos `auth` + `admin` dari grup di atas, lalu
        | dibatasi lagi ke Super Admin. Isinya sendiri tidak sensitif — hanya
        | markup statis, tanpa satu pun data nyata — tapi tetap dikunci supaya
        | tidak ada yang menemukannya lalu mengira situsnya benar-benar rusak.
        |
        | Status HTTP-nya sengaja 200, bukan kode aslinya. Mengembalikan 500
        | di sini berarti pratinjau ikut tercatat sebagai galat di monitoring
        | dan memicu notifikasi untuk sesuatu yang sedang sengaja dilihat.
        |
        */
        Route::get('/pratinjau-galat/{kode}', function (string $kode) {
            abort_unless(in_array($kode, ['403', '404', '419', '500'], true), 404);

            abort_unless(
                request()->user()?->hasPermission('role.manage') ?? false,
                403,
                'Pratinjau halaman galat hanya untuk Super Admin.'
            );

            return response()->view("errors.{$kode}", [
                'exception' => new \Symfony\Component\HttpKernel\Exception\HttpException(
                    (int) $kode,
                    'Contoh pesan: Anda tidak memiliki izin untuk membuka halaman ini.'
                ),
            ]);
        })->name('pratinjau-galat');

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

        /*
        |----------------------------------------------------------------------
        | Permintaan drama
        |----------------------------------------------------------------------
        |
        | Memakai izin `drama.manage`: yang menindaklanjuti permintaan adalah
        | orang yang bisa menambahkan dramanya ke katalog. Memberikannya ke
        | peran yang tidak bisa membuat drama berarti ia hanya bisa membaca
        | daftar keinginan tanpa bisa memenuhinya.
        |
        */
        Route::controller(Admin\DramaRequestController::class)
            ->prefix('drama-request')->name('drama-request.')
            ->middleware('permission:drama.manage')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/{id}', 'update')->name('update')->whereNumber('id');
                Route::post('/{id}/renotify', 'renotify')->name('renotify')->whereNumber('id');
                Route::delete('/{id}', 'destroy')->name('destroy')->whereNumber('id');
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

            /*
            |------------------------------------------------------------------
            | Naik-turun status admin
            |------------------------------------------------------------------
            |
            | Dijaga `admin.manage`, izin yang sama dengan modul Akun Admin —
            | bukan `user.manage` yang menjaga grup ini. Memutuskan siapa yang
            | menjadi admin adalah keputusan yang sama dengan membuat akun
            | admin baru, dan menaruhnya di bawah izin yang lebih longgar
            | berarti siapa pun yang boleh memblokir pengguna juga boleh
            | mengangkat dirinya sendiri lewat akun lain.
            |
            | Izinnya BERTUMPUK dengan grup: pemakainya harus boleh melihat
            | pengguna DAN boleh mengelola admin. Itu memang gabungan yang
            | dimaksud — halaman ini adalah daftar pengguna, bukan pintu
            | belakang menuju pengelolaan admin.
            |
            */
            Route::post('/{id}/promote', 'promote')
                ->name('promote')->whereNumber('id')
                ->middleware('permission:admin.manage');

            Route::post('/{id}/demote', 'demote')
                ->name('demote')->whereNumber('id')
                ->middleware('permission:admin.manage');
            Route::delete('/{id}', 'destroy')->name('destroy')->whereNumber('id');
            Route::post('/bulk', 'bulk')->name('bulk');
        });

        // --- Langganan: aksi tambahan di luar CRUD standar ---
        //
        // Dua route ini sebelumnya tidak memakai middleware izin sama sekali:
        // cukup lolos `admin` saja. Artinya siapa pun yang bisa membuka panel
        // dapat memperpanjang atau membatalkan langganan orang lain dengan satu
        // POST, tanpa pernah membuka halaman Langganan. Disamakan dengan CRUD
        // -nya di atas (`membership.manage`).
        Route::middleware('permission:membership.manage')->group(function () {
            Route::post('/subscription/{id}/renew', [Admin\SubscriptionController::class, 'renew'])
                ->name('subscription.renew')->whereNumber('id');
            Route::post('/subscription/{id}/cancel', [Admin\SubscriptionController::class, 'cancel'])
                ->name('subscription.cancel')->whereNumber('id');
        });

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

            /*
            |------------------------------------------------------------------
            | Kirim katalog ke channel
            |
            | Memakai `telegram.manage`, izin yang sama dengan Broadcast.
            | Keduanya mengirim pesan atas nama bot ke banyak orang sekaligus,
            | dan tidak ada alasan seseorang boleh melakukan yang satu tapi
            | tidak yang lain.
            |------------------------------------------------------------------
            */
            Route::get('/channel-post', [Admin\ChannelPostController::class, 'index'])
                ->name('channel-post.index');

            Route::post('/channel-post', [Admin\ChannelPostController::class, 'send'])
                ->name('channel-post.send');

            // Kirim beberapa drama sekaligus. Endpoint sendiri, bukan
            // parameter tambahan pada 'send' di atas: yang satu mengirim
            // seketika dan melaporkan hasilnya, yang ini hanya mengantrekan.
            // Menyatukan keduanya berarti satu aksi dengan dua arti pesan
            // sukses yang berbeda.
            Route::post('/channel-post/bulk', [Admin\ChannelPostController::class, 'bulk'])
                ->name('channel-post.bulk');

            /*
            |------------------------------------------------------------------
            | Pengumuman bebas ke channel
            |
            | Controller sendiri, panel menumpang di halaman Kirim ke Channel.
            | Izinnya sama: keduanya menulis ke channel yang sama, dibaca
            | orang yang sama.
            |------------------------------------------------------------------
            */
            Route::post('/channel-post/pengumuman', [Admin\ChannelAnnouncementController::class, 'store'])
                ->name('channel-announcement.store');

            Route::post('/channel-post/pengumuman/{pengumuman}/kirim-ulang', [Admin\ChannelAnnouncementController::class, 'resend'])
                ->name('channel-announcement.resend')->whereNumber('pengumuman');

            Route::post('/channel-post/pengumuman/{pengumuman}/batal', [Admin\ChannelAnnouncementController::class, 'cancel'])
                ->name('channel-announcement.cancel')->whereNumber('pengumuman');

            /*
            |------------------------------------------------------------------
            | Kirim poster ke grup partner
            |
            | Halaman sendiri, BUKAN tab di Kirim ke Channel. Tujuannya lain
            | (grup partner, bukan channel pelanggan), isinya lain (poster dan
            | judul saja, tanpa daftar episode maupun tautan bot), dan
            | pembacanya lain. Satu tombol Kirim yang artinya bergantung pada
            | tab mana yang sedang terbuka adalah kiriman salah alamat yang
            | menunggu terjadi — dan di sini yang melihatnya orang, bukan log.
            |------------------------------------------------------------------
            */
            Route::get('/partner-poster', [Admin\PartnerPosterController::class, 'index'])
                ->name('partner-poster.index');

            // Mengantrekan seluruh drama yang belum pernah dikirim.
            Route::post('/partner-poster/kirim', [Admin\PartnerPosterController::class, 'bulk'])
                ->name('partner-poster.bulk');

            // Satu drama, termasuk yang sudah pernah dikirim. Endpoint sendiri
            // karena maksudnya berlawanan dengan yang di atas: "kirim lagi
            // yang ini" versus "lanjutkan yang belum".
            //
            // `{drama:id}`, BUKAN `{drama}`. `Drama::getRouteKeyName()`
            // mengembalikan 'slug', jadi `{drama}` polos membuat Laravel
            // mencari berdasarkan slug — sementara `whereNumber` di bawah
            // hanya menerima angka. Dua aturan yang saling meniadakan, dan
            // akibatnya halaman ini sempat 500 sebelum satu barisnya sempat
            // dirender.
            Route::post('/partner-poster/{drama:id}', [Admin\PartnerPosterController::class, 'one'])
                ->name('partner-poster.one')->whereNumber('drama');
        });

        /*
        |----------------------------------------------------------------------
        | Pembayaran (Phase 10)
        |
        | Dulu satu blok `membership.manage` untuk semuanya. Dipecah menjadi
        | dua izin karena isinya dua hal yang berbeda beratnya:
        |
        |   payment.manage — konfigurasi metode bayar (berisi kredensial
        |                    provider) dan tindakan yang memindahkan uang:
        |                    verifikasi transaksi, ACC manual, batal tagihan.
        |
        |   finance.view   — membaca nominal: daftar tagihan, log pembayaran.
        |
        | Keduanya sensitif dan tidak diberikan RoleSeeder ke peran mana pun
        | selain super-admin. Setelah pembaruan ini WAJIB menjalankan
        | `php artisan db:seed --class=RoleSeeder` di server; tanpa itu izinnya
        | belum ada di database dan halaman-halaman ini 403 untuk semua orang
        | kecuali Root Owner dan super admin.
        |----------------------------------------------------------------------
        */
        Route::middleware('permission:payment.manage')->group(function () {

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

            // Membatalkan tagihan dan memverifikasi transaksi mengubah status
            // pembayaran, jadi keduanya ikut `payment.manage` — bukan
            // `finance.view` yang hanya untuk membaca.
            Route::post('/payment/invoice/{number}/cancel', [Admin\InvoiceController::class, 'cancel'])
                ->name('invoice.cancel');
            Route::post('/payment/transaction/{id}/verify', [Admin\InvoiceController::class, 'verify'])
                ->name('invoice.verify')->whereNumber('id');

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

        /*
        |----------------------------------------------------------------------
        | Gambar bukti bayar
        |
        | Di LUAR grup `payment.manage` dengan sengaja. Buktinya tampil di dua
        | halaman yang izinnya berbeda — ACC Manual (payment.manage) dan detail
        | Tagihan (finance.view) — jadi menaruhnya di salah satu grup membuat
        | gambarnya 403 di halaman yang satunya, dan 403 pada tag <img> terlihat
        | persis seperti berkas yang hilang.
        |
        | Berkasnya TIDAK disajikan sebagai berkas statis; lihat docblock
        | ManualApprovalController::proof(). Singkatnya: bukti bayar memuat
        | nomor rekening, dan berkas di bawah public/ bisa dibuka tanpa login.
        |----------------------------------------------------------------------
        */
        Route::get('/payment/proof/{id}', [Admin\ManualApprovalController::class, 'proof'])
            ->middleware('permission:payment.manage,finance.view')
            ->name('manual-approval.proof')->whereNumber('id');

        /*
        |----------------------------------------------------------------------
        | Membaca angka: tagihan dan log pembayaran
        |
        | Dipisahkan dari `payment.manage` supaya super admin bisa memberi
        | seseorang akses baca laporan keuangan tanpa sekaligus memberi kunci
        | ke kredensial provider pembayaran.
        |----------------------------------------------------------------------
        */
        Route::middleware('permission:finance.view')->group(function () {

            Route::get('/payment/invoice', [Admin\InvoiceController::class, 'index'])
                ->name('invoice.index');
            Route::get('/payment/invoice/export', [Admin\InvoiceController::class, 'export'])
                ->name('invoice.export');
            Route::get('/payment/invoice/{number}', [Admin\InvoiceController::class, 'show'])
                ->name('invoice.show');

            Route::get('/payment/log', [Admin\PaymentLogController::class, 'index'])
                ->name('payment-log.index');
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
        | Memakai `payment.manage`, bukan `membership.manage`. Halaman ini
        | memutuskan komisi dibayar atau tidak dan memproses penarikan dana —
        | uang keluar, sama seperti ACC manual. Persentase komisi dan saldo
        | afiliasi juga termasuk angka yang ingin disembunyikan dari admin
        | biasa.
        |
        */
        Route::prefix('referral')->name('referral.')
            ->middleware('permission:payment.manage')
            ->controller(Admin\ReferralController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/settings', 'updateSettings')->name('settings');
                Route::put('/tiers', 'updateTiers')->name('tiers');

                // Rate komisi khusus untuk satu orang. Satu endpoint untuk
                // memasang maupun mencabut: kotak persen yang dikosongkan
                // berarti orang itu kembali ikut tingkatan otomatis.
                Route::put('/rate-khusus', 'updateCustomRate')->name('rate.custom');
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

                // Membatalkan satu pemasangan. Jalan kembali untuk video yang
                // masuk ke part yang salah: partnya dikosongkan lagi dan
                // videonya kembali ke daftar Belum terpasang, siap dipilihkan
                // drama dan part yang benar lewat alur yang sama seperti
                // biasa. Berkas di storage provider tidak disentuh sama
                // sekali — yang berubah hanya catatannya.
                //
                // POST, bukan DELETE: yang terjadi bukan penghapusan video
                // melainkan pengembalian statusnya, dan tombolnya berupa form
                // biasa di halaman inbox.
                Route::post('/{video}/lepas', 'release')
                    ->name('release')->whereNumber('video');

                // Memindahkan video yang sudah terpasang ke drama/part lain
                // dalam satu langkah. Melepas lalu memasang ulang menghasilkan
                // keadaan yang sama, tapi di antara keduanya part lama sudah
                // kosong sementara yang baru belum terisi — dan admin harus
                // mencari lagi berkasnya di tab sebelah. Di sini keduanya satu
                // transaksi, jadi tidak ada jeda seperti itu.
                Route::post('/{video}/pindah', 'move')
                    ->name('move')->whereNumber('video');
            });
    });
});

