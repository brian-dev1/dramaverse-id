<?php

namespace App\Http\Middleware;

use App\Services\Admin\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode pemeliharaan yang dikendalikan dari panel admin.
 *
 * Berbeda dengan `php artisan down`, ini hanya menutup halaman publik —
 * panel admin dan webhook Telegram tetap dapat diakses supaya Anda bisa
 * mematikannya kembali tanpa masuk ke server.
 */
class CheckMaintenanceMode
{
    public function __construct(
        protected SettingService $settings
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (string) $this->settings->get('maintenance_mode', '0') === '1';

        if (! $enabled) {
            return $next($request);
        }

        // Admin yang sudah masuk tetap bisa melihat situs.
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        return response()->view('web.pages.maintenance', [
            'message' => $this->settings->get(
                'maintenance_text',
                'Kami sedang berbenah. Silakan kembali beberapa saat lagi.'
            ),
        ], 503);
    }
}
