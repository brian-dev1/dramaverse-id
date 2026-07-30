<?php

namespace App\Services\Telegram;

use App\Services\Monitoring\AlertService;
use App\Services\Telegram\Exceptions\TelegramException;

/**
 * Kosakata peringatan khusus Telegram.
 *
 * Sejak Phase 9, penahan dan pengirimannya ada di `AlertService` — kelas ini
 * hanya menyusun judul dan kalimatnya. Pemisahannya disengaja: Phase 9 butuh
 * peringatan untuk backup, antrean, penyimpanan, dan basis data juga, dan
 * menyalin logika penahan ke masing-masing berarti empat salinan dari satu
 * aturan.
 *
 * Tanda tangan seluruh method di sini tidak berubah, jadi pemanggil sejak
 * Sprint 8.9 — `TelegramVideoSyncService`, `SyncEpisodeVideoToTelegram`,
 * `TelegramAutomation` — tidak perlu disentuh sama sekali.
 */
class TelegramAlertService
{
    public function __construct(
        protected AlertService $alerts
    ) {
    }

    /** Sinkronisasi satu video gagal. */
    public function syncFailed(int $videoId, string $sebab): void
    {
        $this->alerts->send(
            'telegram-sync-failed',
            'Sinkronisasi video gagal',
            "Video #{$videoId} gagal dikirim ke Telegram.\n\n".$sebab,
            ['video_id' => $videoId, 'sebab' => $sebab]
        );
    }

    /** Pekerjaan antrean berakhir di failed_jobs. */
    public function queueFailed(string $job, string $sebab): void
    {
        $this->alerts->send(
            'telegram-queue-failed',
            'Pekerjaan antrean gagal',
            "{$job} berhenti dengan galat.\n\n".$sebab,
            ['job' => $job, 'sebab' => $sebab]
        );
    }

    /** Telegram menolak, dan bukan karena kesalahan pengguna. */
    public function apiError(string $method, TelegramException $e): void
    {
        $this->alerts->send(
            'telegram-api-error-'.$method,
            'Telegram API menolak',
            "Method `{$method}` gagal.\n\n".$e->getMessage()
                .($e->hint() ? "\n\n".$e->hint() : ''),
            $e->logContext()
        );
    }

    /**
     * Bot tidak menjawab getMe.
     *
     * Kritis: bot yang mati berarti seluruh pengguna kehilangan satu-satunya
     * cara masuk ke situs. Penahan dilewati.
     */
    public function botOffline(string $sebab): void
    {
        $this->alerts->critical(
            'telegram-bot-offline',
            'Bot tidak menjawab',
            "getMe gagal — bot tidak bisa dihubungi.\n\n".$sebab,
            ['sebab' => $sebab]
        );
    }

    /** Perintah terjadwal berhenti dengan galat. */
    public function schedulerError(string $perintah, string $sebab): void
    {
        $this->alerts->send(
            'scheduler-'.$perintah,
            'Perintah terjadwal gagal',
            "`{$perintah}` berhenti dengan galat.\n\n".$sebab,
            ['perintah' => $perintah, 'sebab' => $sebab]
        );
    }
}
