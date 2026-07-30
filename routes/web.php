<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\TelegramAuthController;
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
    Route::get('/top-rated', 'topRated')->name('web.top-rated');
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

    Route::get('/settings', [Web\SettingController::class, 'index'])->name('web.settings');
    Route::put('/settings', [Web\SettingController::class, 'update'])->name('web.settings.update');

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
            'subscription' => Admin\SubscriptionController::class,
            'role'         => Admin\RoleController::class,
        ];

        // Izin yang diperlukan tiap modul CRUD.
        $crudPermissions = [
            'drama'        => 'drama.manage',
            'episode'      => 'episode.manage',
            'genre'        => 'taxonomy.manage',
            'country'      => 'taxonomy.manage',
            'banner'       => 'taxonomy.manage',
            'membership'   => 'membership.manage',
            'subscription' => 'membership.manage',
            'role'         => 'role.manage',
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
        });

        Route::get('/logs', [Admin\LogController::class, 'index'])
            ->name('logs.index')->middleware('permission:log.view');

        /*
        |----------------------------------------------------------------------
        | Storage Manager
        |
        | 7.2A: daftar baca-saja. 7.2B: tambah provider. 7.2C: ubah, hapus
        | (soft delete), pulihkan. 7.2D: enable, disable, set default,
        | update priority.
        |
        | `bulk` dan Test Connection sengaja BELUM didaftarkan. Itu bukan
        | sekadar catatan: crud/index.blade.php memeriksa Route::has() sebelum
        | merender setiap tombol, jadi selama route-nya tidak ada, tombolnya
        | tidak pernah muncul. Tombol Enable, Disable, Set Default, dan editor
        | prioritas muncul dengan sendirinya begitu route di bawah ada.
        |
        | Dua izin diterima di tiap baris. `storage.view` dan `storage.manage`
        | baru ditambahkan ke daftar izin, sehingga barisnya belum ada di
        | database sampai RoleSeeder dijalankan ulang. Tanpa `setting.manage`
        | sebagai alternatif, menu akan tersembunyi dan halamannya 403 di
        | server yang belum di-seed — gejala yang mudah disalahartikan
        | sebagai bug.
        |----------------------------------------------------------------------
        */
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
    });
});
