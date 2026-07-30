<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\LogFileReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pembaca log Telegram.
 *
 * Pembacaan berkasnya diangkat ke `LogFileReader` di Phase 9, ketika halaman
 * Log Sistem membutuhkan parser yang sama. Menyalinnya berarti dua definisi
 * tentang format log yang sama, dan yang satu akan tertinggal saat formatnya
 * berubah.
 *
 * Halaman ini tetap ada terpisah karena penyaring `telegram.` adalah yang
 * paling sering dibuka, dan menyembunyikannya di balik dropdown halaman lain
 * membuat satu langkah tambahan pada pekerjaan yang paling sering dilakukan.
 */
class TelegramLogController extends Controller
{
    private const LIMIT = 200;

    public function __construct(
        protected LogFileReader $log
    ) {
    }

    public function index(Request $request): View
    {
        $level = (string) $request->query('level', '');

        $q = trim((string) $request->query('q', ''));

        $baris = array_values(array_filter(
            $this->log->tail('telegram.', 5000),
            fn (array $b) => ($level === '' || $b['level'] === $level)
                && ($q === '' || stripos($b['pesan'], $q) !== false)
        ));

        return view('web.pages.admin.telegram-log', [
            'entries' => array_slice($baris, 0, self::LIMIT),
            'level'   => $level,
            'q'       => $q,
            'levels'  => ['error', 'warning', 'info'],
            'file'    => $this->log->path(),
            'ada'     => $this->log->exists(),
        ]);
    }
}
