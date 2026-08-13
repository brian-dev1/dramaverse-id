<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencatat permintaan yang lebih lambat dari ambang batas.
 *
 * ## Kenapa ada
 *
 * "Terasa lama" bukan angka, dan tanpa angka penelusuran berubah jadi
 * rangkaian tebakan: mungkin database, mungkin jaringan, mungkin aset. Setiap
 * tebakan menuntut satu putaran deploy untuk dibantah, dan yang paling sering
 * terjadi adalah semua tebakan terbantah sementara keluhannya tetap ada.
 *
 * Pengukuran di sisi server menghilangkan seluruh putaran itu. Yang tercatat
 * hanya permintaan yang benar-benar lambat, dengan rute dan durasinya — jadi
 * pertanyaannya berhenti menjadi "apakah server lambat" dan langsung menjadi
 * "halaman mana, berapa lama, sesering apa".
 *
 * ## Kenapa `terminate`, bukan sesudah `$next`
 *
 * `terminate()` berjalan setelah jawaban dikirim ke peramban, jadi pencatatan
 * ini tidak menambah satu milidetik pun pada waktu yang diukurnya. Mengukur
 * dari `LARAVEL_START` juga membuat angkanya mencakup boot framework — bagian
 * yang justru paling sering jadi penyebab ketika SEMUA halaman terasa berat,
 * dan yang tidak akan terlihat kalau pengukuran baru dimulai di middleware.
 *
 * ## Ambangnya sengaja tinggi
 *
 * Bawaannya 1000 ms. Log yang mencatat setiap permintaan akan tenggelam oleh
 * lalu lintas normal dan berhenti dibaca orang — dan log yang tidak dibaca
 * sama tidak bergunanya dengan log yang tidak ada. Atur lewat `SLOW_REQUEST_MS`
 * bila perlu lebih peka.
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $ambang = (int) config('app.slow_request_ms', 1000);

        if ($ambang <= 0) {
            return;
        }

        $mulai = defined('LARAVEL_START') ? LARAVEL_START : null;

        if ($mulai === null) {
            return;
        }

        $ms = (int) round((microtime(true) - $mulai) * 1000);

        if ($ms < $ambang) {
            return;
        }

        Log::warning('slow_request', [
            'ms'     => $ms,
            'metode' => $request->getMethod(),
            // Path, bukan URL penuh: query string kerap memuat kata kunci
            // pencarian dan token, dan keduanya tidak menambah apa pun pada
            // pertanyaan "halaman mana yang lambat".
            'path'   => '/'.ltrim($request->path(), '/'),
            'rute'   => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'user'   => $request->user()?->id,
        ]);
    }
}
