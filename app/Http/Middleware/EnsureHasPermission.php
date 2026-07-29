<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi route berdasarkan izin.
 *
 * Dipakai sebagai `->middleware('permission:drama.manage')`.
 */
class EnsureHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyPermission($permissions)) {
            abort(403, 'Anda tidak memiliki izin untuk membuka halaman ini.');
        }

        return $next($request);
    }
}
