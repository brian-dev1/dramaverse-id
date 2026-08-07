<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar.
 *
 * Nginx sudah memasang sebagian, tapi memasangnya di aplikasi membuat
 * perlindungan ikut terbawa bila server web diganti atau ditambah.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',

            // Telegram Web memuat Mini App di dalam iframe, jadi proteksi
            // clickjacking dipindah ke CSP frame-ancestors yang punya
            // daftar izin — X-Frame-Options tidak bisa melakukan itu.
            'Content-Security-Policy' => "frame-ancestors 'self' https://web.telegram.org https://*.telegram.org https://telegram.org",
        ];

        // Panel admin tidak boleh diindeks maupun disematkan di situs lain.
        if ($request->is('admin', 'admin/*')) {
            $headers['X-Robots-Tag']    = 'noindex, nofollow';
            $headers['X-Frame-Options'] = 'DENY';
            $headers['Cache-Control']   = 'no-store, no-cache, must-revalidate';
            $headers['Content-Security-Policy'] = "frame-ancestors 'none'";
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value, false);
        }

        return $response;
    }
}
