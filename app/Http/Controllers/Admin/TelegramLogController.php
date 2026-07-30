<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

/**
 * Pembaca log Telegram.
 *
 * Seluruh lapisan Telegram menulis baris berawalan `telegram.` ke log
 * Laravel. Sampai sekarang membacanya berarti masuk ke server dan menjalankan
 * `grep` — halaman ini menghilangkan langkah itu untuk keadaan yang paling
 * sering ditanyakan: kenapa satu pengiriman gagal.
 *
 * ## Kenapa membaca berkas, bukan tabel
 *
 * Menyimpan riwayat panggilan ke tabel tersendiri berarti setiap pengiriman
 * menambah satu baris database, dan tabel itu tumbuh secepat pemakaian bot
 * tanpa ada yang memangkasnya. Log Laravel sudah berputar sendiri lewat
 * channel `daily`, dan isinya sudah lengkap sejak Sprint 8.1.
 *
 * ## Kenapa dibaca dari belakang
 *
 * Berkas log bisa puluhan megabyte. Yang dicari selalu kejadian terbaru,
 * jadi berkasnya dibaca mundur dari ujung dan berhenti setelah cukup baris
 * terkumpul — bukan dimuat seluruhnya lalu diambil ekornya.
 */
class TelegramLogController extends Controller
{
    /** Baris yang ditampilkan sekali muat. */
    private const LIMIT = 200;

    /** Batas byte yang dibaca dari ujung berkas: 2 MB. */
    private const TAIL_BYTES = 2097152;

    public function index(Request $request): View
    {
        $level = (string) $request->query('level', '');

        $q = trim((string) $request->query('q', ''));

        $baris = $this->readTail();

        $baris = array_values(array_filter(
            $baris,
            fn (array $b) => ($level === '' || $b['level'] === $level)
                && ($q === '' || stripos($b['pesan'], $q) !== false)
        ));

        return view('web.pages.admin.telegram-log', [
            'entries' => array_slice($baris, 0, self::LIMIT),
            'level'   => $level,
            'q'       => $q,
            'levels'  => ['error', 'warning', 'info'],
            'file'    => $this->logPath(),
            'ada'     => is_file($this->logPath()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembacaan
    |--------------------------------------------------------------------------
    */

    /**
     * Baris `telegram.` terbaru, terbaru lebih dulu.
     *
     * @return array<int,array{waktu:string, level:string, event:string, pesan:string}>
     */
    private function readTail(): array
    {
        $path = $this->logPath();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $handle = fopen($path, 'r');

            if ($handle === false) {
                return [];
            }

            $ukuran = filesize($path) ?: 0;

            // Lompat ke dekat ujung berkas. Baris pertama yang terbaca
            // kemungkinan terpotong di tengah — dibuang di parser karena
            // tidak cocok dengan pola tanggalnya.
            if ($ukuran > self::TAIL_BYTES) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
            }

            $isi = stream_get_contents($handle);

            fclose($handle);

        } catch (Throwable) {
            return [];
        }

        $hasil = [];

        foreach (explode("\n", (string) $isi) as $baris) {

            if (! str_contains($baris, 'telegram.')) {
                continue;
            }

            // Bentuk baku Laravel: [2026-07-31 03:00:00] production.ERROR: pesan
            if (! preg_match('/^\[([^\]]+)\]\s+\S+\.(\w+):\s*(.*)$/', $baris, $cocok)) {
                continue;
            }

            $pesan = $cocok[3];

            preg_match('/telegram\.[\w.]+/', $pesan, $ev);

            $hasil[] = [
                'waktu' => $cocok[1],
                'level' => strtolower($cocok[2]),
                'event' => $ev[0] ?? 'telegram',
                'pesan' => mb_substr($pesan, 0, 600),
            ];
        }

        return array_reverse($hasil);
    }

    private function logPath(): string
    {
        // Channel `daily` menulis ke laravel-YYYY-MM-DD.log, `single` ke
        // laravel.log. Yang harian didahulukan karena itu yang dipakai
        // produksi; berkas hari ini yang paling mungkin dicari.
        $harian = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');

        return is_file($harian) ? $harian : storage_path('logs/laravel.log');
    }
}
