<?php

namespace App\Http\Middleware;

use App\Providers\AuthServiceProvider;
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
            abort(403, $this->pesan($permissions));
        }

        return $next($request);
    }

    /**
     * Pesan penolakan yang menyebut sebabnya.
     *
     * "Anda tidak memiliki izin" saja membuat orang mengira ada yang rusak,
     * lalu mencobanya berulang kali. Menyebut bahwa halaman itu memang milik
     * Super Admin mengubah reaksinya dari "coba lagi" menjadi "minta akses".
     */
    private function pesan(array $permissions): string
    {
        $sensitif = array_intersect($permissions, AuthServiceProvider::SENSITIVE);

        if ($sensitif !== []) {
            return 'Halaman ini berisi data keuangan dan pengaturan pembayaran, '
                .'sehingga hanya dapat dibuka oleh Super Admin. '
                .'Hubungi Super Admin bila Anda memerlukan aksesnya.';
        }

        return 'Anda tidak memiliki izin untuk membuka halaman ini.';
    }
}
