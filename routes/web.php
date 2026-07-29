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
        Route::post('/login', 'login')->name('login.attempt');
        Route::post('/logout', 'logout')->name('logout')->middleware('auth');
    });

    // --- Halaman terlindungi ---
    Route::middleware(['auth', 'admin'])->group(function () {

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
        ];

        foreach ($cruds as $key => $controller) {
            Route::controller($controller)->prefix($key)->name($key.'.')->group(function () {
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
        Route::get('/user', [Admin\UserController::class, 'index'])->name('user.index');
        Route::get('/membership', [Admin\MembershipController::class, 'index'])->name('membership.index');
        Route::get('/subscription', [Admin\SubscriptionController::class, 'index'])->name('subscription.index');
        Route::get('/logs', [Admin\LogController::class, 'index'])->name('logs.index');

        Route::get('/report', [Admin\ReportController::class, 'index'])->name('report');

        Route::get('/settings', [Admin\SettingController::class, 'index'])->name('settings');
        Route::put('/settings', [Admin\SettingController::class, 'update'])->name('settings.update');
    });
});
