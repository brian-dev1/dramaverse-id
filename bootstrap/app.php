<?php

use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\VerifyTelegramWebhook;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        api:      __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Berlaku untuk seluruh permintaan web.
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'admin'  => EnsureUserIsAdmin::class,
            'active' => EnsureUserIsActive::class,
            'telegram.webhook' => VerifyTelegramWebhook::class,
            'maintenance' => CheckMaintenanceMode::class,
            'permission' => EnsureHasPermission::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Pengecualian CSRF
        |----------------------------------------------------------------------
        |
        | Keduanya datang dari server luar yang tidak pernah memuat halaman
        | kita, jadi tidak mungkin membawa token CSRF.
        |
        | `payment/callback/*` SEMPAT TERLEWAT. Akibatnya setiap callback
        | pembayaran dijawab 419 sebelum satu baris kode pun berjalan —
        | gateway menganggapnya gagal, mengirim ulang, dan ditolak lagi.
        | Pembayaran yang sah tidak pernah mengaktifkan membership, dan tidak
        | ada satu pun galat aplikasi yang muncul karena kodenya memang tidak
        | pernah dijalankan.
        |
        | Keduanya tetap dijaga: webhook Telegram oleh middleware
        | `telegram.webhook`, callback pembayaran oleh verifikasi tanda tangan
        | di dalam driver masing-masing. Dikecualikan dari CSRF bukan berarti
        | terbuka.
        |
        */
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'payment/callback/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Input yang TIDAK boleh ikut di-flash ke session saat validasi gagal.
        //
        // Laravel mem-flash seluruh input agar form bisa diisi ulang lewat
        // old(). Untuk kredensial storage itu berarti dua hal sekaligus:
        // secret key tersimpan sebagai teks polos di penyimpanan session, dan
        // dirender kembali ke dalam atribut value pada HTML. Padahal di
        // database nilai yang sama disimpan terenkripsi — melindunginya di
        // satu tempat lalu membocorkannya di tempat lain tidak ada gunanya.
        //
        // Konsekuensi yang disengaja: setelah validasi gagal, kolom kunci
        // kembali kosong dan harus diisi ulang.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'access_key',
            'secret_key',
        ]);
    })->create();
