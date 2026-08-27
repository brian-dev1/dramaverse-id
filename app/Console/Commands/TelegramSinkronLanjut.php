<?php

namespace App\Console\Commands;

use App\Enums\TelegramSyncStatus;
use App\Models\EpisodeVideo;
use App\Services\Telegram\TelegramBulkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isi ulang antrean sinkronisasi supaya tidak perlu ditekan berulang.
 *
 * ## Masalahnya
 *
 * Tombol "Sinkronkan yang menunggu" mengantrekan sejumlah video sekali
 * tekan. Begitu rombongan itu habis, antrean kosong dan sisanya menunggu
 * sampai ada yang menekan tombolnya lagi. Untuk 96 video yang tersisa,
 * itu berarti duduk menunggui layar hanya untuk menekan satu tombol.
 *
 * ## Cara kerjanya
 *
 * Dijalankan penjadwal tiap menit. Ia TIDAK mengantrekan serombongan
 * baru lalu menunggu habis — ia menjaga antrean tetap penuh:
 *
 *     slot kosong = batas - (video sedang diproses + pekerjaan di antrean)
 *
 * Selama masih ada slot, video PENDING berikutnya diantrekan sebanyak
 * itu. Begitu penuh, perintah ini keluar tanpa melakukan apa pun.
 *
 * Menjaga antrean tetap penuh lebih baik daripada menunggu rombongan
 * habis: dengan cara kedua, worker menganggur setiap kali menunggu satu
 * video terakhir yang kebetulan paling besar selesai.
 *
 * ## Kenapa hitungannya memakai `jobs`, bukan cuma PROCESSING
 *
 * Video yang sudah diantrekan tetapi belum diambil worker masih
 * berstatus PENDING — tidak ada penanda lain yang membedakannya dari
 * yang belum diantrekan sama sekali. Menghitung PROCESSING saja berarti
 * perintah ini akan mengantrekan video yang sama berulang kali setiap
 * menit sampai worker sempat mengambilnya.
 *
 * ```
 * php artisan telegram:sinkron-lanjut            # dipakai penjadwal
 * php artisan telegram:sinkron-lanjut --paksa    # walau dimatikan di config
 * php artisan telegram:sinkron-lanjut --batas=20
 * ```
 */
class TelegramSinkronLanjut extends Command
{
    protected $signature = 'telegram:sinkron-lanjut
                            {--batas= : Timpa batas antrean dari konfigurasi}
                            {--paksa : Jalankan walau auto_continue dimatikan}';

    protected $description = 'Jaga antrean sinkronisasi Telegram tetap penuh tanpa ditekan manual';

    public function handle(TelegramBulkService $bulk): int
    {
        if (! $this->bolehJalan()) {
            return self::SUCCESS;
        }

        $batas = $this->batas();

        $terpakai = $this->sedangDikerjakan();

        $slot = $batas - $terpakai;

        if ($slot <= 0) {
            // Antrean masih penuh. Diam saja — perintah ini berjalan tiap
            // menit, dan mencetak sesuatu setiap kali akan membanjiri log
            // penjadwal dengan kabar bahwa tidak ada yang perlu dikabarkan.
            return self::SUCCESS;
        }

        $ids = EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::PENDING->value)
            ->orderBy('id')
            ->limit($slot)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return self::SUCCESS;
        }

        $hasil = $bulk->sync($ids);

        $this->info(
            "{$hasil['queued']} video diantrekan "
            ."(terpakai {$terpakai}/{$batas} sebelum ini)."
        );

        foreach (array_slice($hasil['skipped'], 0, 5) as $alasan) {
            $this->warn('  dilewati '.$alasan);
        }

        Log::info('telegram.sinkron_lanjut', [
            'diantrekan' => $hasil['queued'],
            'dilewati'   => count($hasil['skipped']),
            'terpakai'   => $terpakai,
            'batas'      => $batas,
        ]);

        return self::SUCCESS;
    }

    private function bolehJalan(): bool
    {
        if ($this->option('paksa')) {
            return true;
        }

        return (bool) config('telegram.sync.auto_continue', true);
    }

    private function batas(): int
    {
        $diminta = $this->option('batas');

        if (is_numeric($diminta)) {
            return max(1, (int) $diminta);
        }

        return max(1, (int) config('telegram.sync.batch_limit', 50));
    }

    /**
     * Berapa video yang sedang dikerjakan atau sudah menunggu di antrean.
     */
    private function sedangDikerjakan(): int
    {
        $diproses = EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::PROCESSING->value)
            ->count();

        return $diproses + $this->menungguDiAntrean();
    }

    /**
     * Pekerjaan yang masih menunggu di tabel `jobs`.
     *
     * Hanya berlaku untuk driver antrean `database`. Driver lain — redis,
     * sqs — tidak menyimpan antreannya di sini, dan menebak isinya lebih
     * berbahaya daripada mengembalikan nol: yang terburuk dari nol adalah
     * satu putaran mengantrekan agak banyak, sementara tebakan yang salah
     * bisa membuat antrean tidak pernah diisi sama sekali.
     */
    private function menungguDiAntrean(): int
    {
        $koneksi = config('queue.default');

        if (config("queue.connections.{$koneksi}.driver") !== 'database') {
            return 0;
        }

        $antrean = config('telegram.sync.queue') ?: 'default';

        try {
            return DB::table(config("queue.connections.{$koneksi}.table", 'jobs'))
                ->where('queue', $antrean)
                ->count();
        } catch (Throwable $galat) {
            Log::warning('telegram.sinkron_lanjut.gagal_hitung_antrean', [
                'pesan' => $galat->getMessage(),
            ]);

            return 0;
        }
    }
}
