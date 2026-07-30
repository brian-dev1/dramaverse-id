<?php

namespace App\Jobs;

use App\Enums\TelegramSyncStatus;
use App\Models\EpisodeVideo;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Memastikan `telegram_file_id` yang tersimpan masih bisa dipakai.
 *
 * ## Kenapa ini perlu ada
 *
 * Sampai Sprint 8.6, `file_id` disimpan sekali lalu dipercaya selamanya.
 * Telegram bisa membuang berkas lama, dan bila itu terjadi gejalanya adalah
 * pengguna menekan tombol lalu mendapat galat — bukan admin yang diberi tahu
 * ada yang perlu disinkronkan ulang.
 *
 * ## Caranya: getFile, bukan mengirim ulang
 *
 * `getFile` menanyakan metadata berkas tanpa mengirim apa pun ke siapa pun.
 * Murah, dan tidak mengganggu pengguna mana pun. Mengirim video ke chat
 * penyimpanan untuk menguji akan menambah satu salinan setiap kali diperiksa.
 *
 * Bila ditolak, status dikembalikan ke FAILED dengan sebabnya — sehingga ia
 * muncul di panel dan bisa disinkronkan ulang **dari storage provider**,
 * tanpa ada yang perlu mengunggah apa pun dari komputer.
 */
class VerifyTelegramFileId implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $videoId
    ) {
        $this->onQueue(config('telegram.sync.queue', 'default'));
    }

    public function handle(
        TelegramServiceInterface $telegram,
        TelegramCacheService $cache
    ): void {

        $video = EpisodeVideo::find($this->videoId);

        if ($video === null || ! $video->isSyncedToTelegram()) {
            return;
        }

        try {
            $telegram->withRetries(1)->getFile($video->telegram_file_id);

            $this->log('info', 'verify.ok', $video);

        } catch (TelegramException $e) {

            // Kegagalan jaringan BUKAN berarti file_id-nya buruk. Menandai
            // FAILED karena gangguan sesaat akan membuat seluruh katalog
            // tampak rusak setiap kali koneksi VPS terganggu.
            if ($e->isConnectionProblem() || $e->isRateLimited()) {
                $this->log('warning', 'verify.inconclusive', $video, [
                    'sebab' => $e->getMessage(),
                ]);

                return;
            }

            $video->forceFill([
                'sync_status' => TelegramSyncStatus::FAILED,
                'last_error'  => 'file_id ditolak Telegram saat diverifikasi: '.$e->getMessage()
                    .' Sinkronkan ulang dari storage provider.',
            ])->save();

            $cache->forget($video->episode_id);

            $this->log('error', 'verify.failed', $video, ['sebab' => $e->getMessage()]);
        }
    }

    private function log(string $level, string $event, EpisodeVideo $video, array $extra = []): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->log($level, 'telegram.'.$event, $extra + [
                'video_id'   => $video->id,
                'episode_id' => $video->episode_id,
            ]);
    }
}
