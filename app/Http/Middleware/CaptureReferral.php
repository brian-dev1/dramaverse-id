<?php

namespace App\Http\Middleware;

use App\Services\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menangkap `?ref=KODE` dari tautan affiliate.
 *
 * Kodenya disimpan di cookie, bukan session: pengunjung biasanya mengklik
 * tautan hari ini dan baru berlangganan seminggu kemudian, lewat login
 * Telegram yang me-regenerate session.
 *
 * Ikatan sesungguhnya baru dibuat saat pengguna login (lihat
 * TelegramAuthController), dan hanya bila ia belum punya pengundang.
 */
class CaptureReferral
{
    public function __construct(protected ReferralService $referral)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $kode = $request->query('ref');

        if (blank($kode) || ! $this->referral->enabled()) {
            return $next($request);
        }

        $referrer = $this->referral->findByCode((string) $kode);

        if (! $referrer) {
            return $next($request);
        }

        // Statistik klik. Dibatasi satu sidik jari per hari di dalam service.
        $this->referral->recordVisit($referrer, $request->ip(), $request->userAgent());

        // Sudah login dan belum punya upline → ikat sekarang juga.
        if (Auth::check()) {
            $this->referral->attach(Auth::user(), $referrer->referral_code);

            return $next($request);
        }

        $hari = (int) $this->referral->setting('referral_cookie_days', 30);

        $response = $next($request);

        Cookie::queue(cookie(
            name: 'dv_ref',
            value: $referrer->referral_code,
            minutes: $hari * 24 * 60,
            httpOnly: true,
        ));

        return $response;
    }
}
