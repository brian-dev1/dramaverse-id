<?php

namespace App\Jobs;

use App\Enums\TelegramSyncStatus;
use App\Models\EpisodeVideo;
use App\Services\Telegram\TelegramVideoSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengirim satu video episode dari storage provider ke Telegram.
 *
 * Di antrean, bukan di dalam request, karena pengiriman berkas ratusan
 * megabyte memakan menit — sama persis dengan alasan unggahan ke bucket
 * dipindahkan ke antrean di Sprint 7.7.
 *
 * `tries = 1`: pengulangan diurus service lewat `retry_count`, bukan oleh
 * antrean. Alasannya, antrean akan mengulang seluruh job termasuk mengunduh
 * ulang berkas dari bucket, dan untuk kegagalan yang sudah pasti — berkas
 * terlalu besar, chat penyimpanan salah — itu hanya membuang kuota bucket
 * berkali-kali.
 */
class SyncEpisodeVideoToTelegram implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Batas waktu job. Sedikit lebih longgar daripada batas waktu HTTP-nya,
     * supaya yang menghentikan adalah timeout Telegram yang pesannya jelas,
     * bukan timeout antrean yang tidak menyebutkan apa-apa.
     */
    public int $timeout;

    public function __construct(
        public int $videoId
    ) {
        $this->timeout = (int) config('telegram.sync.timeout', 1800) + 120;

        $this->onQueue(config('telegram.sync.queue', 'default'));
    }

    public function handle(TelegramVideoSyncService $sync): void
    {
        $video = EpisodeVideo::find($this->videoId);

        if ($video === null) {
            // Barisnya dihapus setelah job diantrekan. Bukan kegagalan.
            return;
        }

        try {
            $sync->sync($video);
        } catch (Throwable $e) {

            // Sebabnya sudah tersimpan di `last_error` dan sudah dicatat
            // service. Job tidak melempar lagi supaya tidak berakhir di
            // failed_jobs seolah ada yang perlu diperbaiki di antrean —
            // yang perlu diperbaiki ada di panel, dan tombol Retry Sync
            // sudah menunggu di sana.
            Log::warning('Sinkronisasi video ke Telegram gagal', [
                'video_id' => $this->videoId,
                'sebab'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Worker dibunuh paksa di tengah jalan (restart, OOM) tidak sempat
     * menjalankan catch di atas, dan barisnya akan tersangkut di PROCESSING
     * selamanya. Hook ini yang melepaskannya.
     */
    public function failed(?Throwable $e): void
    {
        $video = EpisodeVideo::find($this->videoId);

        if ($video === null || $video->sync_status !== TelegramSyncStatus::PROCESSING) {
            return;
        }

        $video->forceFill([
            'sync_status' => TelegramSyncStatus::FAILED,
            'last_error'  => $e?->getMessage()
                ?: 'Pekerjaan antrean berhenti sebelum selesai — worker restart, '
                    .'kehabisan memori, atau melewati batas waktu.',
            'retry_count' => $video->retry_count + 1,
        ])->save();
    }
}
