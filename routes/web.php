<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\DramaController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\Auth\TelegramAuthController;

Route::get('/', HomeController::class)
    ->name('home');

Route::get('/search', SearchController::class)
    ->name('search');

Route::get('/drama/{slug}', DramaController::class)
    ->name('drama.show');

Route::get('/episode/{episode}', EpisodeController::class)
    ->name('episode.show');

Route::get(
    '/auth/telegram/{token}',
    TelegramAuthController::class
)->name('telegram.login');

/*
|--------------------------------------------------------------------------
| Telegram Webhook
|--------------------------------------------------------------------------
*/

Route::post(
    '/telegram/webhook',
    TelegramWebhookController::class
)->name('telegram.webhook');

Route::middleware('auth')->group(function () {

    Route::get('/dashboard', function () {

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'user' => auth()->user(),
        ]);

    })->name('dashboard');

});