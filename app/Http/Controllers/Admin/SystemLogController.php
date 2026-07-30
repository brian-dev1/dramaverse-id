<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\LogFileReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Log seluruh sistem, bukan hanya Telegram.
 *
 * Pembacaannya memakai `LogFileReader` yang sama dengan halaman Log Telegram
 * — bedanya cuma penyaring awalannya. Menulis parser kedua berarti dua
 * definisi tentang format log yang sama, dan yang satu akan tertinggal saat
 * formatnya berubah.
 *
 * Halaman ini melengkapi `/admin/logs`, bukan menggantikannya: yang itu
 * membaca tabel `activity_logs` (siapa melakukan apa), yang ini membaca
 * berkas log (apa yang rusak).
 */
class SystemLogController extends Controller
{
    private const LIMIT = 200;

    /**
     * Awalan yang bisa disaring.
     *
     * Daftar tertutup, bukan menerima teks apa pun sebagai awalan: nilai dari
     * query string yang langsung dipakai menyaring isi berkas adalah cara
     * membaca baris yang tidak dimaksudkan untuk ditampilkan.
     */
    private const CHANNELS = [
        ''          => 'Semua',
        'telegram.' => 'Telegram',
        'storage.'  => 'Storage',
        'upload.'   => 'Upload',
        'backup.'   => 'Backup',
        'alert.'    => 'Peringatan',
        'auth.'     => 'Autentikasi',
    ];

    public function __construct(
        protected LogFileReader $log
    ) {
    }

    public function index(Request $request): View
    {
        $channel = (string) $request->query('channel', '');

        if (! array_key_exists($channel, self::CHANNELS)) {
            $channel = '';
        }

        $level = (string) $request->query('level', '');

        $q = trim((string) $request->query('q', ''));

        $baris = $this->log->tail($channel ?: null, 5000);

        $baris = array_values(array_filter(
            $baris,
            fn (array $b) => ($level === '' || $b['level'] === $level)
                && ($q === '' || stripos($b['pesan'], $q) !== false)
        ));

        return view('web.pages.admin.system-log', [
            'entries'  => array_slice($baris, 0, self::LIMIT),
            'channel'  => $channel,
            'channels' => self::CHANNELS,
            'level'    => $level,
            'q'        => $q,
            'levels'   => ['error', 'critical', 'warning', 'info'],
            'counts'   => $this->log->levelCounts($channel ?: null),
            'file'     => $this->log->path(),
            'size'     => $this->log->size(),
            'ada'      => $this->log->exists(),
        ]);
    }
}
