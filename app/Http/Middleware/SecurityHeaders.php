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
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
        ];

        // Panel admin tidak boleh diindeks maupun disematkan di situs lain.
        if ($request->is('admin', 'admin/*')) {
            $headers['X-Robots-Tag']    = 'noindex, nofollow';
            $headers['X-Frame-Options'] = 'DENY';
            $headers['Cache-Control']   = 'no-store, no-cache, must-revalidate';
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value, false);
        }

        return $response;
    }
}
