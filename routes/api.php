<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Api\Internal\VideoInboxController;
use App\Http\Controllers\Api\Internal\VideoUploadTargetController;
use App\Http\Controllers\Web\WebSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DramaVerse ID — API Routes
|--------------------------------------------------------------------------
|
| Cakupan sengaja dibatasi pada endpoint yang benar-benar dipanggil oleh
| frontend: pencarian realtime dan sinkronisasi progres pemutar.
| Endpoint lain menyusul saat fiturnya dikerjakan.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {

    // --- Pencarian realtime (publik) ---
    Route::get('/search', [WebSearchController::class, 'ajax'])->name('search');

    // --- Pemutar (butuh sesi login) ---
    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('/player/progress', Api\ProgressController::class)->name('player.progress');
        Route::get('/player/resume/{episode}', Api\PlayerResumeController::class)->name('player.resume');
        Route::post('/player/completed/{episode}', Api\PlayerCompletedController::class)->name('player.completed');

        Route::get('/notifications', [Api\NotificationController::class, 'index'])->name('notifications');
    });
});

Route::post('/internal/video-inbox', [VideoInboxController::class, 'store'])
    ->middleware('throttle:60,1');

Route::get('/internal/video-upload-target', [VideoUploadTargetController::class, 'show'])
    ->middleware('throttle:60,1');

Route::post('/internal/video-upload-target', [VideoUploadTargetController::class, 'store'])
    ->middleware('throttle:60,1');