<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnalyticsPeriod;
use App\Http\Controllers\Controller;
use App\Services\Admin\CsvExporter;
use App\Services\Admin\XlsxWriter;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan: dilihat, diekspor, dicetak.
 *
 * ## Yang berubah di Phase 11
 *
 * Judul kolom dan query setiap laporan dulu ditulis di controller ini.
 * Sekarang di `ReportService`, dan controller tinggal menangani HTTP.
 *
 * Bukan sekadar pemindahan: tiga jalur di bawah — layar, ekspor, cetak —
 * memanggil `rows()` dan `headers()` yang sama persis. Selama definisinya ada
 * di controller, menambah satu laporan berarti menyunting tiga tempat yang
 * kebetulan bersebelahan, dan itu jenis duplikasi yang paling mudah lolos
 * karena terlihat rapi.
 *
 * Tiga laporan baru ikut masuk tanpa satu baris pun ditambahkan di sini:
 * tagihan, sinkronisasi Telegram, dan penyimpanan.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reports,
        protected AnalyticsService $analytics,
        protected CsvExporter $csv,
        protected XlsxWriter $xlsx
    ) {
    }

    public function index(Request $request): View
    {
        [$type, $from, $to] = $this->params($request);

        $rows = $this->reports->rows($type, $from, $to);

        // Grafik pendamping dibaca dari AnalyticsService, sumber yang sama
        // dengan dashboard. Menghitungnya sendiri di sini akan menghasilkan
        // dua angka pendapatan berbeda di dua halaman.
        $keuangan = $this->analytics->financial(AnalyticsPeriod::MONTH);

        return view('web.pages.admin.report', [
            'types'   => ReportService::TYPES,
            'type'    => $type,
            'from'    => $from,
            'to'      => $to,
            'rows'    => $rows->take((int) config('analytics.report.preview_rows', 100)),
            'headers' => $this->reports->headers($type),
            'total'   => $rows->count(),
            'revenue' => $keuangan['perPeriod'],
        ]);
    }

    /** Unduh laporan sebagai CSV atau XLSX. */
    public function export(Request $request, string $format = 'csv')
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        [$type, $from, $to] = $this->params($request);

        $name = sprintf('%s-%s-sd-%s', $type, $from->toDateString(), $to->toDateString());

        $headers = $this->reports->headers($type);

        $rows = $this->reports->rows($type, $from, $to);

        return $format === 'xlsx'
            ? $this->xlsx->download($name, $headers, $rows, $this->reports->label($type))
            : $this->csv->stream($name, $headers, $rows);
    }

    /**
     * Tampilan siap cetak.
     *
     * PDF dihasilkan lewat dialog cetak peramban (Simpan sebagai PDF).
     * Membuat berkas PDF dari PHP membutuhkan paket tambahan yang sengaja
     * tidak dipasang — dan dialog cetak sudah menghasilkan PDF yang sama
     * baiknya tanpa satu pun dependensi baru.
     */
    public function print(Request $request): View
    {
        [$type, $from, $to] = $this->params($request);

        return view('web.pages.admin.report-print', [
            'title'   => $this->reports->label($type),
            'from'    => $from,
            'to'      => $to,
            'headers' => $this->reports->headers($type),
            'rows'    => $this->reports->rows($type, $from, $to),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Jenis laporan dan rentang tanggalnya.
     *
     * Jenis yang tidak dikenal jatuh ke `watch`, TIDAK abort 404. Tautan lama
     * yang jenisnya sudah dihapus lebih baik membuka laporan bawaan daripada
     * memberi halaman galat kepada orang yang cuma membuka bookmark.
     *
     * @return array{0:string,1:Carbon,2:Carbon}
     */
    private function params(Request $request): array
    {
        $type = (string) $request->query('type', 'watch');

        if (! $this->reports->exists($type)) {
            $type = 'watch';
        }

        [$from, $to] = $this->reports->range(
            $request->date('from'),
            $request->date('to')
        );

        return [$type, $from, $to];
    }
}
