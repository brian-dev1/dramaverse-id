<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * Hanya akun admin yang aktif dan tidak diblokir yang boleh
         * menggunakan panel.
         */
        if (! $user?->canAccessAdmin()) {
            /*
             * Bila user masih mempunyai sesi autentikasi, putus sesi
             * tersebut. Ini penting ketika akun admin dinonaktifkan atau
             * diblokir saat browsernya masih login.
             */
            if ($user !== null) {
                Auth::logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()
                ->route('admin.login')
                ->with('error', 'Sesi admin tidak valid atau akun Anda sudah tidak memiliki akses.');
        }

        return $next($request);
    }
}