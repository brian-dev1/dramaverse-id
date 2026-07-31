<?php

namespace App\Services\Admin;

use App\Support\Concerns\NormalisesExportValues;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Penulis berkas XLSX tanpa paket pihak ketiga.
 *
 * XLSX pada dasarnya adalah arsip ZIP berisi beberapa berkas XML. PHP sudah
 * membawa ZipArchive, jadi format ini bisa ditulis langsung — hasilnya
 * berkas Excel sungguhan, bukan CSV yang diganti ekstensinya.
 *
 * Cakupannya sengaja terbatas: satu lembar, baris judul tebal, kolom
 * melebar otomatis. Cukup untuk laporan, dan tidak menambah dependensi.
 */
class XlsxWriter
{
    use NormalisesExportValues;

    /** Batas kolom yang didukung (A sampai ZZ). */
    private const MAX_COLUMNS = 702;

    /**
     * @param  array<string>  $headers
     * @param  iterable       $rows
     */
    public function download(string $filename, array $headers, iterable $rows, string $sheetName = 'Laporan'): BinaryFileResponse
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Tidak dapat membuat berkas XLSX sementara.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($headers, $rows));

        $zip->close();

        $name = str_replace(['"', '/', '\\', "\n", "\r"], '', $filename).'.xlsx';

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian-bagian arsip
    |--------------------------------------------------------------------------
    */

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        $name = htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_QUOTES | ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Dua gaya: 0 = biasa, 1 = tebal untuk baris judul. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    private function sheet(array $headers, array $rows): string
    {
        $widths = $this->columnWidths($headers, $rows);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        $xml .= '<cols>';
        foreach ($widths as $i => $width) {
            $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';
        $xml .= $this->row(1, $headers, style: 1);

        $line = 2;
        foreach ($rows as $row) {
            $xml .= $this->row($line++, array_values((array) $row));
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private function row(int $number, array $cells, int $style = 0): string
    {
        $xml = '<row r="'.$number.'">';

        foreach (array_values($cells) as $i => $value) {
            if ($i >= self::MAX_COLUMNS) {
                break;
            }

            $ref = $this->columnLetter($i).$number;
            $xml .= $this->cell($ref, $value, $style);
        }

        return $xml.'</row>';
    }

    private function cell(string $ref, mixed $value, int $style): string
    {
        $attr = $style > 0 ? ' s="'.$style.'"' : '';

        $value = $this->normalise($value);

        // Angka ditulis sebagai numerik supaya bisa dijumlahkan di Excel.
        //
        // Dibatasi pada bilangan wajar: nilai berawalan nol (nomor telepon)
        // dan bilangan sangat panjang (ID Telegram) tetap ditulis sebagai
        // teks, karena Excel akan memotong nol depan atau mengubahnya
        // menjadi notasi ilmiah.
        if ($this->isPlainNumber($value)) {
            return '<c r="'.$ref.'"'.$attr.'><v>'.$value.'</v></c>';
        }

        $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1);

        return '<c r="'.$ref.'"'.$attr.' t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
    }

    private function isPlainNumber(string $value): bool
    {
        if ($value === '' || ! is_numeric($value)) {
            return false;
        }

        if (strlen($value) > 15) {
            return false;
        }

        // Nol di depan bermakna (nomor telepon, kode) — jangan diangkakan.
        if ($value !== '0' && str_starts_with($value, '0') && ! str_starts_with($value, '0.')) {
            return false;
        }

        return true;
    }


    /** Perkiraan lebar kolom dari isi terpanjang. */
    private function columnWidths(array $headers, array $rows): array
    {
        $widths = array_map(fn ($h) => mb_strlen((string) $h) + 2, array_values($headers));

        foreach (array_slice($rows, 0, 200) as $row) {
            foreach (array_values((array) $row) as $i => $value) {
                if (! isset($widths[$i])) {
                    continue;
                }

                $len = mb_strlen($this->normalise($value)) + 2;
                $widths[$i] = min(60, max($widths[$i], $len));
            }
        }

        return $widths;
    }

    /** 0 => A, 25 => Z, 26 => AA */
    private function columnLetter(int $index): string
    {
        $letter = '';

        while ($index >= 0) {
            $letter = chr($index % 26 + 65).$letter;
            $index = intdiv($index, 26) - 1;
        }

        return $letter;
    }
}
