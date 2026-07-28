<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak akun yang diblokir dan mencatat waktu aktivitas terakhir.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->is_banned || ! $user->is_active)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('web.home')
                ->with('status', 'Akun Anda tidak aktif. Hubungi admin melalui Telegram.');
        }

        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInMinutes(now()) >= 5)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
