<?php

namespace App\Support;

/**
 * Format ukuran berkas.
 *
 * Diangkat ke sini di Phase 12: `EpisodeVideo::getSizeForHumansAttribute()`
 * dan `StoredFile::sizeForHumans()` berisi kode yang sama persis, dan
 * keduanya adalah tampilan angka — bukan aturan bisnis milik salah satunya.
 */
final class Bytes
{
    /** @var array<string> */
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /**
     * Ubah jumlah byte jadi bentuk yang enak dibaca.
     *
     * Byte utuh ditampilkan tanpa desimal — "512 B" lebih jelas daripada
     * "512.00 B". Satuan di atasnya selalu dua desimal supaya lebar
     * kolomnya tidak berubah-ubah di dalam tabel.
     */
    public static function forHumans(int|float|null $bytes): string
    {
        $size = (float) ($bytes ?? 0);

        $i = 0;

        while ($size >= 1024 && $i < count(self::UNITS) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : number_format($size, 2)).' '.self::UNITS[$i];
    }
}
