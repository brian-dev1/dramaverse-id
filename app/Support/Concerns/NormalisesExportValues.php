<?php

namespace App\Support\Concerns;

use Illuminate\Support\Carbon;

/**
 * Menyeragamkan nilai sebelum ditulis ke berkas ekspor.
 *
 * Dipakai CsvExporter dan XlsxWriter. Sebelum Phase 12 keduanya punya
 * salinannya sendiri — dan format tanggal yang berbeda di dua jenis ekspor
 * dari data yang sama adalah cacat yang baru terlihat saat orang
 * membandingkan hasilnya.
 */
trait NormalisesExportValues
{
    protected function normalise(mixed $value): string
    {
        return match (true) {
            $value instanceof Carbon => $value->format('Y-m-d H:i'),
            is_bool($value)          => $value ? 'Ya' : 'Tidak',
            is_null($value)          => '',
            default                  => (string) $value,
        };
    }
}
