<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor CSV memakai PHP bawaan, tanpa paket tambahan.
 *
 * Ditulis sebagai streaming response supaya laporan besar tidak menahan
 * seluruh baris di memori sekaligus.
 */
class CsvExporter
{
    /**
     * @param  array<string>  $headers  Judul kolom
     * @param  iterable       $rows     Baris data, tiap baris berupa array
     */
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $name = str_replace(['"', "\n", "\r"], '', $filename);

        return response()->stream(
            function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');

                // BOM UTF-8 supaya Excel membaca karakter Indonesia dengan benar.
                fwrite($out, "\xEF\xBB\xBF");

                fputcsv($out, $headers, ';');

                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->normalise(...), $row), ';');
                }

                fclose($out);
            },
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
                'Cache-Control'       => 'no-store, no-cache',
            ]
        );
    }

    /** Menyeragamkan nilai agar aman ditulis ke CSV. */
    private function normalise(mixed $value): string
    {
        return match (true) {
            $value instanceof Carbon => $value->format('Y-m-d H:i'),
            is_bool($value)          => $value ? 'Ya' : 'Tidak',
            is_null($value)          => '',
            default                  => (string) $value,
        };
    }
}
