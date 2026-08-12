<?php

use App\Http\Middleware\BlockProbeRequests;
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
        /*
        |----------------------------------------------------------------------
        | Penolak pemindai — dijalankan paling awal
        |----------------------------------------------------------------------
        |
        | `prepend` ke grup global, bukan ke grup `web`. Bedanya penting:
        | permintaan ke `/wp-admin/setup-config.php` tidak cocok dengan route
        | mana pun, jadi ia tidak pernah masuk grup `web` — ia langsung jatuh
        | ke penangan 404. Middleware yang dipasang di `web` tidak akan pernah
        | melihatnya, dan pemindai tetap bebas mengetuk ribuan kali.
        |
        | Di grup global ia berjalan sebelum route dicocokkan, sebelum sesi
        | dibuka, dan sebelum satu query pun jalan. Itu memang tujuannya:
        | permintaan yang pasti ditolak harus semurah mungkin ditolaknya.
        |
        */
        $middleware->prepend(BlockProbeRequests::class);

        // Berlaku untuk seluruh permintaan web.
        $middleware->web(append: [
            SecurityHeaders::class,
            // Menangkap ?ref=KODE dari tautan affiliate pada halaman mana pun.
            \App\Http\Middleware\CaptureReferral::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Ke mana tamu diarahkan
        |----------------------------------------------------------------------
        |
        | Bawaan Laravel mengarahkan ke route bernama `login`. Route itu TIDAK
        | ADA di proyek ini: satu-satunya form login bernama `admin.login`, dan
        | pengguna biasa tidak login lewat form sama sekali — mereka masuk
        | otomatis lewat Telegram.
        |
        | Akibatnya setiap halaman ber-middleware `auth` yang dibuka tamu
        | menjawab 500 RouteNotFoundException, bukan mengarahkan ke mana pun.
        | Selama halaman itu hanya Favorit dan Riwayat — yang tidak pernah
        | ditautkan kepada tamu — kesalahannya tidak pernah terlihat. Halaman
        | Request Drama mengubahnya: tombolnya muncul di hasil pencarian
        | kosong, dan pencarian bisa dilakukan siapa saja.
        |
        */
        $middleware->redirectGuestsTo(fn () => route('web.home').'?masuk=1');

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

            // Mini App membuktikan dirinya lewat tanda tangan HMAC pada
            // initData, bukan lewat token sesi. Di Telegram Desktop/Web
            // halaman berjalan di dalam iframe pihak ketiga, dan cookie
            // sesi — termasuk cookie XSRF — belum tentu ikut terkirim pada
            // permintaan pertama. Menuntut token CSRF di sini berarti login
            // otomatis gagal 419 sebelum tanda tangannya sempat diperiksa.
            'auth/telegram/miniapp',
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
