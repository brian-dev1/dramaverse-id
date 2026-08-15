<?php

namespace App\Services\Analytics;

use App\Enums\PaymentStatus;
use App\Models\EpisodeVideo;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kumpulan laporan yang bisa dilihat, diekspor, dan dicetak.
 *
 * ## Satu definisi per laporan
 *
 * Judul kolom dan barisnya ditulis SEKALI di sini, lalu dipakai tiga
 * pemanggil: tampilan layar, ekspor CSV/XLSX, dan halaman cetak. Sebelum
 * Phase 11 ketiganya ada di `ReportController` — masih satu tempat, tetapi
 * bercampur dengan penanganan HTTP, sehingga menambah satu laporan berarti
 * menyunting controller.
 *
 * ## Pendapatan dibaca dari invoice
 *
 * Laporan pendapatan sebelumnya menjumlahkan `subscriptions.price`. Sejak
 * Phase 10 itu keliru: langganan yang diberikan admin punya harga tetapi
 * tidak ada uang yang masuk, dan biaya layanan provider hanya tercatat di
 * invoice. Yang dihitung sekarang adalah invoice berstatus lunas.
 */
class ReportService
{
    /** Jenis laporan: kunci => label. */
    public const TYPES = [
        'watch'      => 'Laporan tontonan',
        'membership' => 'Laporan membership',
        'revenue'    => 'Laporan pendapatan',
        'invoice'    => 'Laporan tagihan',
        'telegram'   => 'Laporan pengguna Telegram',
        'sync'       => 'Laporan sinkronisasi Telegram',
        'storage'    => 'Laporan penyimpanan',
    ];

    /**
     * Laporan yang memuat nominal rupiah.
     *
     * `membership` ikut masuk meski namanya bukan soal uang: kolom Harga ada
     * di setiap barisnya, dan menjumlahkan satu kolom di Excel adalah cara
     * termudah mendapatkan angka pendapatan yang justru disembunyikan.
     *
     * @var list<string>
     */
    public const FINANCIAL = ['revenue', 'invoice', 'membership'];

    public function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public function isFinancial(string $type): bool
    {
        return in_array($type, self::FINANCIAL, true);
    }

    /**
     * Daftar jenis laporan yang boleh dipilih seseorang.
     *
     * @return array<string,string>
     */
    public function typesFor(bool $bolehKeuangan): array
    {
        return $bolehKeuangan
            ? self::TYPES
            : array_diff_key(self::TYPES, array_flip(self::FINANCIAL));
    }

    public function label(string $type): string
    {
        return self::TYPES[$type] ?? 'Laporan';
    }

    /** @return array<int,string> */
    public function headers(string $type): array
    {
        return match ($type) {

            'membership' => ['Pengguna', 'Paket', 'Harga', 'Status', 'Sumber', 'Mulai', 'Berakhir'],

            'revenue' => ['Tanggal bayar', 'Nomor', 'Pengguna', 'Paket', 'Subtotal', 'Biaya', 'Total'],

            'invoice' => ['Nomor', 'Dibuat', 'Pengguna', 'Paket', 'Total', 'Status', 'Dibayar'],

            'telegram' => ['Nama', 'Username Telegram', 'ID Telegram', 'Aktif', 'Terakhir masuk', 'Bergabung'],

            'sync' => ['Drama', 'Part', 'Ukuran (byte)', 'Status', 'Percobaan', 'Disinkron', 'Galat terakhir'],

            'storage' => ['Drama', 'Part', 'Provider', 'Ukuran (byte)', 'Diunggah'],

            default => ['Pengguna', 'Drama', 'Part', 'Progres (detik)', 'Selesai', 'Terakhir ditonton'],
        };
    }

    /**
     * Baris laporan dalam rentang tanggal.
     *
     * Dibatasi `analytics.report.max_rows`. Tanpa batas, ekspor setahun penuh
     * membaca seluruh tabel ke memori dan menghentikan proses PHP di tengah
     * unduhan — yang sampai ke admin sebagai berkas rusak, bukan pesan galat.
     */
    public function rows(string $type, Carbon $from, Carbon $to): Collection
    {
        $batas = (int) config('analytics.report.max_rows', 20000);

        return match ($type) {

            'membership' => Subscription::query()
                ->with(['user:id,name', 'plan:id,name'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($batas)
                ->get()
                ->map(fn ($s) => [
                    $s->user?->name,
                    $s->plan?->name,
                    $s->price,
                    $s->status,
                    $s->source,
                    $s->started_at,
                    $s->expired_at,
                ]),

            'revenue' => Invoice::query()
                ->with(['user:id,name'])
                ->where('status', PaymentStatus::PAID->value)
                ->whereBetween('paid_at', [$from, $to])
                ->latest('paid_at')
                ->limit($batas)
                ->get()
                ->map(fn ($i) => [
                    $i->paid_at,
                    $i->number,
                    $i->user?->name,
                    $i->plan_name,
                    $i->subtotal,
                    $i->fee,
                    $i->total,
                ]),

            'invoice' => Invoice::query()
                ->with(['user:id,name'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($batas)
                ->get()
                ->map(fn ($i) => [
                    $i->number,
                    $i->created_at,
                    $i->user?->name,
                    $i->plan_name,
                    $i->total,
                    $i->status->label(),
                    $i->paid_at,
                ]),

            'telegram' => User::query()
                ->whereNotNull('telegram_id')
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($batas)
                ->get()
                ->map(fn ($u) => [
                    $u->name,
                    $u->telegram_username,
                    $u->telegram_id,
                    $u->is_active,
                    $u->last_login_at,
                    $u->created_at,
                ]),

            'sync' => EpisodeVideo::query()
                ->with(['episode.drama:id,title'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($batas)
                ->get()
                ->map(fn ($v) => [
                    $v->episode?->drama?->title,
                    $v->episode?->episode_number,
                    $v->size,
                    $v->sync_status->label(),
                    $v->retry_count,
                    $v->synced_at,
                    $v->last_error,
                ]),

            'storage' => EpisodeVideo::query()
                ->with(['episode.drama:id,title', 'provider:id,name'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($batas)
                ->get()
                ->map(fn ($v) => [
                    $v->episode?->drama?->title,
                    $v->episode?->episode_number,
                    $v->provider?->name,
                    $v->size,
                    $v->created_at,
                ]),

            default => WatchHistory::query()
                ->with(['user:id,name', 'drama:id,title', 'episode:id,episode_number'])
                ->whereBetween('last_watched_at', [$from, $to])
                ->latest('last_watched_at')
                ->limit($batas)
                ->get()
                ->map(fn ($h) => [
                    $h->user?->name,
                    $h->drama?->title,
                    $h->episode?->episode_number,
                    $h->progress,
                    $h->completed,
                    $h->last_watched_at,
                ]),
        };
    }

    /**
     * Rentang tanggal dari masukan, bawaan 30 hari terakhir.
     *
     * Tanggal terbalik ditukar, bukan ditolak: admin yang keliru mengisi
     * urutannya jelas memaksudkan rentang di antara keduanya, dan pesan galat
     * di sini hanya menambah satu langkah tanpa mencegah apa pun.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public function range(?Carbon $from, ?Carbon $to): array
    {
        $from ??= now()->subDays(29);

        $to ??= now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }
}
