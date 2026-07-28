<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan permintaan webhook benar-benar datang dari Telegram.
 *
 * Endpoint webhook dikecualikan dari CSRF dan dapat diakses publik, jadi
 * tanpa pemeriksaan ini siapa pun bisa mengirim update palsu ke bot kita.
 *
 * Telegram mengirim nilai yang kita daftarkan lewat `secret_token` pada
 * header X-Telegram-Bot-Api-Secret-Token setiap kali memanggil webhook.
 */
class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('telegram.webhook_secret');

        // Belum dikonfigurasi: tolak di produksi, izinkan saat pengembangan lokal.
        if (blank($expected)) {
            if (app()->environment('production')) {
                Log::warning('Webhook Telegram ditolak: TELEGRAM_WEBHOOK_SECRET belum diisi.');

                abort(403);
            }

            return $next($request);
        }

        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! hash_equals($expected, $received)) {
            Log::warning('Webhook Telegram ditolak: secret token tidak cocok.', [
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        return $next($request);
    }
}
