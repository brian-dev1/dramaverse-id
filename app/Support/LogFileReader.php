<?php

namespace App\Support;

use Throwable;

/**
 * Membaca ekor berkas log Laravel.
 *
 * ## Kenapa ada sebagai kelas tersendiri
 *
 * Sprint 8.9 menaruh pembacaan ini di dalam `TelegramLogController`. Begitu
 * Phase 9 butuh pembaca log yang sama untuk seluruh sistem, pilihannya jadi
 * dua: menyalinnya, atau mengangkatnya ke sini. Menyalinnya berarti dua
 * parser untuk format yang sama, dan yang satu akan tertinggal saat format
 * lognya berubah.
 *
 * ## Kenapa dibaca dari belakang
 *
 * Berkas log produksi bisa puluhan megabyte. Yang dicari selalu kejadian
 * terbaru, jadi berkasnya dibaca dari ujung dan dibatasi byte — bukan dimuat
 * seluruhnya lalu diambil ekornya. Baris pertama yang terbaca kemungkinan
 * terpotong di tengah; ia dibuang karena tidak cocok dengan pola tanggalnya.
 */
class LogFileReader
{
    /** Batas byte yang dibaca dari ujung berkas: 2 MB. */
    public const TAIL_BYTES = 2097152;

    /**
     * Baris log terbaru lebih dulu.
     *
     * @param  string|null  $prefix  hanya baris yang memuat teks ini, mis. `telegram.`
     * @return array<int,array{waktu:string, level:string, event:string, pesan:string}>
     */
    public function tail(?string $prefix = null, int $limit = 200): array
    {
        $isi = $this->rawTail();

        if ($isi === '') {
            return [];
        }

        $hasil = [];

        foreach (explode("\n", $isi) as $baris) {

            if ($prefix !== null && ! str_contains($baris, $prefix)) {
                continue;
            }

            // Bentuk baku Laravel: [2026-07-31 03:00:00] production.ERROR: pesan
            if (! preg_match('/^\[([^\]]+)\]\s+\S+\.(\w+):\s*(.*)$/', $baris, $cocok)) {
                continue;
            }

            $pesan = $cocok[3];

            // Nama peristiwa berpola `modul.aksi` bila ada; kalau tidak, kelas
            // exception-nya yang jadi penanda. Keduanya lebih berguna daripada
            // memotong 40 karakter pertama pesannya.
            preg_match('/^[a-z][\w]*\.[\w.]+/', $pesan, $ev)
                ?: preg_match('/^\S+Exception/', $pesan, $ev);

            $hasil[] = [
                'waktu' => $cocok[1],
                'level' => strtolower($cocok[2]),
                'event' => $ev[0] ?? '-',
                'pesan' => mb_substr($pesan, 0, 600),
            ];
        }

        return array_slice(array_reverse($hasil), 0, $limit);
    }

    /**
     * Jumlah baris per level dalam ekor berkas.
     *
     * Dipakai dashboard monitoring sebagai "statistik galat". Ini bukan
     * hitungan seluruh riwayat — hanya potongan terakhir, dan itu memang yang
     * dimaksud: yang penting bagi operator adalah apakah galat sedang terjadi
     * SEKARANG, bukan berapa banyak yang pernah terjadi sejak dulu.
     *
     * @return array<string,int>
     */
    public function levelCounts(?string $prefix = null): array
    {
        $hasil = ['error' => 0, 'warning' => 0, 'info' => 0, 'lainnya' => 0];

        foreach ($this->tail($prefix, 5000) as $baris) {

            $level = $baris['level'];

            if ($level === 'critical' || $level === 'alert' || $level === 'emergency') {
                $level = 'error';
            }

            array_key_exists($level, $hasil)
                ? $hasil[$level]++
                : $hasil['lainnya']++;
        }

        return $hasil;
    }

    /**
     * Berkas log yang sedang dipakai.
     *
     * Channel `daily` menulis ke laravel-YYYY-MM-DD.log, `single` ke
     * laravel.log. Yang harian didahulukan karena itu yang dipakai produksi.
     */
    public function path(): string
    {
        $harian = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');

        return is_file($harian) ? $harian : storage_path('logs/laravel.log');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Ukuran berkas log dalam byte, 0 bila belum ada. */
    public function size(): int
    {
        return $this->exists() ? (int) filesize($this->path()) : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    private function rawTail(): string
    {
        $path = $this->path();

        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        try {
            $handle = fopen($path, 'r');

            if ($handle === false) {
                return '';
            }

            if ((filesize($path) ?: 0) > self::TAIL_BYTES) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
            }

            $isi = (string) stream_get_contents($handle);

            fclose($handle);

            return $isi;

        } catch (Throwable) {
            return '';
        }
    }
}
