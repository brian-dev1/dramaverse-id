<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Admin\CsvExporter;
use App\Services\Admin\StatsService;
use App\Services\Admin\XlsxWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Jenis laporan yang tersedia. */
    private const TYPES = [
        'watch'      => 'Laporan tontonan',
        'membership' => 'Laporan membership',
        'revenue'    => 'Laporan pendapatan',
        'telegram'   => 'Laporan pengguna Telegram',
    ];

    public function __construct(
        protected StatsService $stats,
        protected CsvExporter $csv,
        protected XlsxWriter $xlsx
    ) {
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $type = $request->get('type', 'watch');
        $type = array_key_exists($type, self::TYPES) ? $type : 'watch';

        return view('web.pages.admin.report', [
            'types'   => self::TYPES,
            'type'    => $type,
            'from'    => $from,
            'to'      => $to,
            'rows'    => $this->rows($type, $from, $to)->take(100),
            'headers' => $this->headers($type),
            'total'   => $this->rows($type, $from, $to)->count(),
            'revenue' => $this->stats->revenuePerMonth(12),
            'watch'   => $this->stats->watchPerMonth(12),
        ]);
    }

    /** Unduh laporan sebagai CSV atau XLSX. */
    public function export(Request $request, string $format = 'csv')
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        [$from, $to] = $this->range($request);

        $type = $request->get('type', 'watch');
        abort_unless(array_key_exists($type, self::TYPES), 404);

        $name = sprintf('%s-%s-sd-%s', $type, $from->toDateString(), $to->toDateString());

        $headers = $this->headers($type);
        $rows    = $this->rows($type, $from, $to);

        return $format === 'xlsx'
            ? $this->xlsx->download($name, $headers, $rows, self::TYPES[$type])
            : $this->csv->stream($name, $headers, $rows);
    }

    /**
     * Tampilan siap cetak.
     *
     * PDF dihasilkan lewat dialog cetak peramban (Simpan sebagai PDF) —
     * membuat berkas PDF sungguhan dari PHP membutuhkan paket tambahan
     * yang sengaja tidak dipasang.
     */
    public function print(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $type = $request->get('type', 'watch');
        abort_unless(array_key_exists($type, self::TYPES), 404);

        return view('web.pages.admin.report-print', [
            'title'   => self::TYPES[$type],
            'from'    => $from,
            'to'      => $to,
            'headers' => $this->headers($type),
            'rows'    => $this->rows($type, $from, $to),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Data per jenis laporan
    |--------------------------------------------------------------------------
    */

    private function headers(string $type): array
    {
        return match ($type) {
            'watch'      => ['Pengguna', 'Drama', 'Episode', 'Progres (detik)', 'Selesai', 'Terakhir ditonton'],
            'membership' => ['Pengguna', 'Paket', 'Harga', 'Status', 'Mulai', 'Berakhir'],
            'revenue'    => ['Tanggal', 'Pengguna', 'Paket', 'Harga', 'Status'],
            'telegram'   => ['Nama', 'Username Telegram', 'ID Telegram', 'Aktif', 'Terakhir masuk', 'Bergabung'],
        };
    }

    private function rows(string $type, Carbon $from, Carbon $to)
    {
        return match ($type) {
            'watch' => WatchHistory::query()
                ->with(['user:id,name', 'drama:id,title', 'episode:id,episode_number'])
                ->whereBetween('last_watched_at', [$from, $to])
                ->latest('last_watched_at')
                ->get()
                ->map(fn ($h) => [
                    $h->user?->name,
                    $h->drama?->title,
                    $h->episode?->episode_number,
                    $h->progress,
                    $h->completed,
                    $h->last_watched_at,
                ]),

            'membership' => Subscription::query()
                ->with(['user:id,name', 'plan:id,name'])
                ->whereBetween('created_at', [$from, $to])
                ->latest()
                ->get()
                ->map(fn ($s) => [
                    $s->user?->name,
                    $s->plan?->name,
                    $s->price,
                    $s->status,
                    $s->started_at,
                    $s->expired_at,
                ]),

            'revenue' => Subscription::query()
                ->with(['user:id,name', 'plan:id,name'])
                ->whereIn('status', ['active', 'expired'])
                ->whereBetween('started_at', [$from, $to])
                ->latest('started_at')
                ->get()
                ->map(fn ($s) => [
                    $s->started_at,
                    $s->user?->name,
                    $s->plan?->name,
                    $s->price,
                    $s->status,
                ]),

            'telegram' => User::query()
                ->whereNotNull('telegram_id')
                ->whereBetween('created_at', [$from, $to])
                ->latest()
                ->get()
                ->map(fn ($u) => [
                    $u->name,
                    $u->telegram_username,
                    $u->telegram_id,
                    $u->is_active,
                    $u->last_login_at,
                    $u->created_at,
                ]),
        };
    }

    /** Rentang tanggal, bawaan 30 hari terakhir. */
    private function range(Request $request): array
    {
        $from = $request->date('from') ?: now()->subDays(29);
        $to   = $request->date('to') ?: now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
