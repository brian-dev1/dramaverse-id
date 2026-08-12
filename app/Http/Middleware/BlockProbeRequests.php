<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penolak pemindai otomatis.
 *
 * ## Kenapa ini ada, padahal nginx sudah memblokir hal serupa
 *
 * Nginx memblokir lebih murah dan lebih dulu — itu memang lapis pertamanya.
 * Tapi konfigurasi nginx tidak ikut di dalam repositori: ia hidup di
 * `/etc/nginx` di server, di luar `git`, dan bisa tertinggal saat pindah VPS,
 * tertimpa panel hosting, atau hilang bersama server yang dibangun ulang.
 * Yang ada di sini ikut ke mana pun kodenya dibawa.
 *
 * Keduanya sengaja tumpang tindih. Kalau nginx bekerja, middleware ini tidak
 * pernah kebagian permintaan dan tidak ada yang terbuang. Kalau nginx tidak
 * ada, ini yang menjaga.
 *
 * ## Yang ditolak, dan kenapa daftar ini pendek
 *
 * Hanya jalur yang mustahil dipakai orang sungguhan: panel WordPress di situs
 * Laravel, `.env`, dump `.sql`, phpMyAdmin. Tidak ada satu pun yang bisa
 * diketik pengunjung secara wajar, jadi salah-tolaknya nol.
 *
 * Daftarnya sengaja tidak dipanjangkan menjadi "semua pola serangan yang
 * diketahui". Pemblokiran berbasis daftar hitam selalu kalah cepat dari
 * penyerang yang mengganti pola, dan daftar yang panjang menumbuhkan rasa
 * aman yang keliru — yang menahan SQL injection di sini adalah query
 * berparameter di Eloquent, bukan daftar kata terlarang. Ini hanya menyapu
 * pemindai massal supaya tidak membebani php-fpm dan tidak mengotori log
 * sampai serangan sungguhan tenggelam di dalamnya.
 *
 * ## Kenapa 404, bukan 403
 *
 * 403 memberi tahu pemindai bahwa ada sesuatu yang sengaja dijaga di sana.
 * 404 tidak memberi tahu apa-apa, dan itu jawaban yang sama dengan yang
 * diterima siapa pun yang salah ketik alamat.
 */
class BlockProbeRequests
{
    /**
     * Jalur yang tidak pernah sah di aplikasi ini.
     *
     * Dicocokkan dengan `Str::is()` terhadap path tanpa slash awal.
     */
    private const JALUR_TERLARANG = [
        // WordPress — situs ini tidak memakainya sama sekali.
        'wp-admin*', 'wp-login*', 'wp-content*', 'wp-includes*', 'xmlrpc.php',

        // Berkas rahasia yang kadang bocor karena salah konfigurasi.
        '.env*', '.git*', '.aws*', '.ssh*', 'config.json', 'credentials*',

        // Panel database yang tidak pernah dipasang di domain ini.
        'phpmyadmin*', 'pma*', 'adminer*', 'myadmin*', 'dbadmin*',

        // Cangkang dan dump yang ditinggalkan penyerang lalu dicari ulang.
        '*.sql', '*.bak', '*.old', 'shell.php', 'cmd.php', 'alfa*', 'wso*',

        // Endpoint kerangka kerja lain.
        'vendor/phpunit*', 'telescope*', '_ignition*', 'actuator*',
    ];

    /**
     * Penanda pada User-Agent alat pemindai.
     *
     * Hanya alat yang memang mengumumkan dirinya. Penyerang serius mengganti
     * User-Agent dalam satu baris, jadi ini bukan pertahanan — hanya penyapu
     * lalu lintas bising yang tidak repot menyamar.
     */
    private const AGEN_PEMINDAI = [
        'sqlmap', 'nikto', 'nessus', 'acunetix', 'nmap', 'masscan',
        'zgrab', 'dirbuster', 'gobuster', 'wpscan', 'havij', 'arachni',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim($request->path(), '/');
        $agen = Str::lower((string) $request->userAgent());

        $alasan = match (true) {
            Str::is(self::JALUR_TERLARANG, $path)   => 'jalur',
            Str::contains($agen, self::AGEN_PEMINDAI) => 'agen',
            default                                 => null,
        };

        if ($alasan === null) {
            return $next($request);
        }

        /*
         | Dicatat di kanal terpisah supaya fail2ban punya sesuatu untuk
         | dibaca. Satu permintaan seperti ini tidak berarti apa-apa; dua
         | puluh dalam satu menit dari satu IP berarti pemindaian sedang
         | berjalan, dan itulah yang membuat IP-nya diblokir di lapis
         | firewall — sebelum permintaan berikutnya sempat sampai ke PHP.
         */
        Log::channel('keamanan')->warning('Permintaan pemindai ditolak', [
            'alasan' => $alasan,
            'ip'     => $request->ip(),
            'path'   => $path,
            'agen'   => Str::limit((string) $request->userAgent(), 120),
        ]);

        abort(404);
    }
}
