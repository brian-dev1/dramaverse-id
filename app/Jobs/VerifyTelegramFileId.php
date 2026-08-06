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
 * Bot API `getFile` tidak cocok untuk video besar. Karena itu file yang
 * ukurannya melebihi batas pemeriksaan getFile tidak boleh dianggap rusak
 * hanya karena Telegram menjawab "file is too big".
 *
 * Untuk file yang masih berada dalam batas pemeriksaan, getFile tetap dipakai.
 * Gangguan jaringan/rate-limit dianggap inconclusive, sedangkan penolakan
 * definitif lainnya tetap menandai video FAILED dan membuka persistent issue.
 */
class VerifyTelegramFileId implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Batas aman Bot API getFile yang digunakan verifier ini.
     */
    private const GET_FILE_MAX_BYTES = 20 * 1024 * 1024;

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

        /*
         * File besar tidak diverifikasi dengan getFile karena endpoint tersebut
         * memang dapat menolak berdasarkan ukuran. file_id yang sudah diperoleh
         * dari sinkronisasi tetap dipertahankan dan issue operasional lama boleh
         * ditutup: kegagalan getFile karena ukuran bukan bukti file_id rusak.
         */
        if ($video->size > self::GET_FILE_MAX_BYTES) {
            $video->forceFill([
                'sync_status' => TelegramSyncStatus::SYNCED,
                'last_error'  => null,
            ])->save();

            $video->resolveIssue(
                'Verifikasi getFile dilewati karena ukuran video melebihi batas pemeriksaan Bot API. '
                .'file_id hasil sinkronisasi tetap dipertahankan.'
            );

            $cache->forget($video->episode_id);

            $this->log('info', 'verify.skipped_too_large', $video, [
                'size' => $video->size,
                'limit' => self::GET_FILE_MAX_BYTES,
            ]);

            return;
        }

        try {
            $telegram->withRetries(1)->getFile($video->telegram_file_id);

            $video->forceFill([
                'sync_status' => TelegramSyncStatus::SYNCED,
                'last_error'  => null,
            ])->save();

            $video->resolveIssue(
                'file_id berhasil diverifikasi ke Telegram dan dinyatakan sehat.'
            );

            $cache->forget($video->episode_id);

            $this->log('info', 'verify.ok', $video);

        } catch (TelegramException $e) {

            // Kegagalan jaringan BUKAN berarti file_id-nya buruk.
            if ($e->isConnectionProblem() || $e->isRateLimited()) {
                $this->log('warning', 'verify.inconclusive', $video, [
                    'sebab' => $e->getMessage(),
                ]);

                return;
            }

            /*
             * Defensive fallback. Jika ukuran metadata lokal tidak akurat tetapi
             * Telegram tetap menjawab "file is too big", jangan pernah
             * mengklasifikasikannya sebagai file_id invalid.
             */
            if (str_contains(strtolower($e->getMessage()), 'file is too big')) {
                $video->forceFill([
                    'sync_status' => TelegramSyncStatus::SYNCED,
                    'last_error'  => null,
                ])->save();

                $video->resolveIssue(
                    'Telegram mengenali file_id, tetapi getFile tidak dapat digunakan karena ukuran file terlalu besar.'
                );

                $cache->forget($video->episode_id);

                $this->log('info', 'verify.skipped_too_large', $video, [
                    'sebab' => $e->getMessage(),
                ]);

                return;
            }

            $sebab = 'file_id ditolak Telegram saat diverifikasi: '.$e->getMessage()
                .' Sinkronkan ulang dari storage provider.';

            $video->forceFill([
                'sync_status' => TelegramSyncStatus::FAILED,
                'last_error'  => $sebab,
            ])->save();

            $video->reportIssue($sebab);

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
