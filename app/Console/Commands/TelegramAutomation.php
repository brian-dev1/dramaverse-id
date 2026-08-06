<?php

namespace App\Console\Commands;

use App\Enums\TelegramSyncStatus;
use App\Jobs\SyncEpisodeVideoToTelegram;
use App\Models\EpisodeVideo;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Perawatan otomatis lapisan Telegram, dijalankan scheduler.
 *
 * Tiga pekerjaan dalam satu perintah, dengan sub-perintah:
 *
 *   php artisan telegram:auto retry     ulangi sinkronisasi yang gagal
 *   php artisan telegram:auto health    periksa bot, peringatkan bila mati
 *   php artisan telegram:auto cleanup   lepaskan yang tersangkut, buang sampah
 *   php artisan telegram:auto all       ketiganya
 *
 * Digabung dalam satu kelas, bukan tiga, karena ketiganya berbagi hal yang
 * sama: pembacaan config otomatisasi, penulisan log dengan awalan yang sama,
 * dan penanganan galat yang mengirim peringatan alih-alih mati diam-diam.
 * Tiga kelas berarti tiga salinan dari ketiganya.
 */
class TelegramAutomation extends Command
{
    protected $signature = 'telegram:auto
                            {tugas=all : retry, health, cleanup, atau all}
                            {--limit=10 : Batas video per jalannya untuk retry}';

    protected $description = 'Perawatan otomatis Telegram: retry, health check, cleanup';

    public function __construct(
        protected TelegramHealthService $health,
        protected TelegramAlertService $alerts
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tugas = (string) $this->argument('tugas');

        $daftar = $tugas === 'all' ? ['retry', 'health', 'cleanup'] : [$tugas];

        $gagal = 0;

        foreach ($daftar as $satu) {

            try {
                match ($satu) {
                    'retry'   => $this->retry(),
                    'health'  => $this->healthCheck(),
                    'cleanup' => $this->cleanup(),
                    default   => $this->components->error("Tugas `{$satu}` tidak dikenal."),
                };

            } catch (Throwable $e) {

                // Perintah terjadwal yang mati diam-diam adalah perintah yang
                // dianggap berjalan padahal tidak. Peringatannya dikirim, dan
                // tugas berikutnya tetap dijalankan.
                $gagal++;

                $this->components->error("{$satu} gagal: ".$e->getMessage());

                $this->alerts->schedulerError('telegram:auto '.$satu, $e->getMessage());
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */

    /**
     * Antrekan ulang sinkronisasi yang gagal dan masih di bawah batas.
     *
     * Yang sudah melewati `sync.max_retry` sengaja dibiarkan. Kegagalan
     * permanen — berkas melewati batas 50 MB Bot API, chat penyimpanan salah
     * — akan gagal dengan cara yang sama berapa kali pun dicoba, dan
     * mengulanginya setiap jam hanya memenuhi log sampai kegagalan yang
     * benar-benar baru tidak terlihat lagi.
     */
    private function retry(): void
    {
        if (! config('telegram.automation.auto_retry', true)) {
            $this->components->info('Auto retry dimatikan lewat TELEGRAM_AUTO_RETRY.');

            return;
        }

        $maks = (int) config('telegram.sync.max_retry', 3);

        $videos = EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::FAILED->value)
            ->where('retry_count', '<', $maks)
            ->orderBy('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($videos as $video) {
            $video->forceFill(['sync_status' => TelegramSyncStatus::PENDING])->save();

            SyncEpisodeVideoToTelegram::dispatch($video->id);
        }

        $this->log('auto.retry', ['jumlah' => $videos->count()]);

        $this->components->info($videos->isEmpty()
            ? 'Tidak ada sinkronisasi gagal yang perlu diulang.'
            : $videos->count().' video diantrekan ulang.');
    }

    /*
    |--------------------------------------------------------------------------
    | Health check
    |--------------------------------------------------------------------------
    */

    private function healthCheck(): void
    {
        if (! config('telegram.automation.health_check', true)) {
            return;
        }

        $bot = $this->health->bot();

        if (! $bot['ok']) {
            $this->components->error('Bot tidak menjawab: '.($bot['error'] ?? '-'));

            $this->alerts->botOffline($bot['error'] ?? 'tanpa keterangan');

            return;
        }

        $this->components->info('Bot menjawab: @'.($bot['username'] ?? '?'));

        $antrean = $this->health->queue();

        // Antrean gagal yang menumpuk berarti ada yang berhenti bekerja dan
        // tidak ada yang tahu. Angkanya kumulatif, jadi peringatannya
        // ditahan oleh TelegramAlertService supaya tidak dikirim tiap jam.
        if (($antrean['failed'] ?? 0) > 0) {
            $this->alerts->queueFailed(
                'antrean '.$antrean['queue'],
                $antrean['failed'].' pekerjaan ada di failed_jobs. '
                .'Periksa dengan: php artisan queue:failed'
            );
        }

        $this->log('auto.health', ['bot' => $bot['username'] ?? null] + $antrean);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    /**
     * Lepaskan baris yang tersangkut dan buang berkas sementara yang
     * tertinggal.
     *
     * Baris PROCESSING yang tidak pernah selesai menghalangi percobaan
     * berikutnya selamanya, karena `canStart()` menolak status itu. Worker
     * yang dibunuh paksa tidak sempat melepaskannya sendiri.
     *
     * Berkas `tgsync_*` seharusnya sudah dihapus di blok `finally`
     * TelegramVideoSyncService. Yang tertinggal hanya berasal dari proses
     * yang dibunuh di tengah jalan — dan ukurannya sebesar video, jadi
     * membiarkannya berarti disk VPS penuh tanpa sebab yang terlihat.
     */
    private function cleanup(): void
    {
        $tersangkut = $this->health->stuckQuery()->get();

        foreach ($tersangkut as $video) {
            $sebab = 'Tersangkut di status Diproses melebihi '
                .config('telegram.automation.stuck_minutes').' menit. '
                .'Worker kemungkinan berhenti sebelum selesai. Dilepaskan otomatis.';

            $video->forceFill([
                'sync_status' => TelegramSyncStatus::FAILED,
                'last_error'  => $sebab,
                'retry_count' => $video->retry_count + 1,
            ])->save();

            $video->reportIssue($sebab);
        }

        $berkas = $this->purgeTempFiles();

        $this->log('auto.cleanup', [
            'tersangkut'   => $tersangkut->count(),
            'berkas_hapus' => $berkas,
        ]);

        $this->components->info(sprintf(
            '%d baris tersangkut dilepaskan, %d berkas sementara dihapus.',
            $tersangkut->count(),
            $berkas
        ));
    }

    /** @return int jumlah berkas yang dihapus */
    private function purgeTempFiles(): int
    {
        $dihapus = 0;

        // Satu jam: cukup longgar supaya sinkronisasi yang sedang berjalan
        // tidak kehilangan berkasnya di tengah pengiriman.
        $batas = time() - 3600;

        foreach (glob(sys_get_temp_dir().'/tgsync_*') ?: [] as $path) {

            if (! is_file($path) || filemtime($path) > $batas) {
                continue;
            }

            if (@unlink($path)) {
                $dihapus++;
            }
        }

        return $dihapus;
    }

    private function log(string $event, array $context): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.'.$event, $context);
    }
}
