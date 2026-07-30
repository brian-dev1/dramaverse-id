<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\BroadcastService;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mengumumkan satu episode ke satu chat Telegram.
 *
 * Isi pesannya disusun BroadcastService, bukan di sini — lihat alasannya di
 * kelas itu.
 */
class BroadcastEpisodeTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        protected Episode $episode,
        protected string $chatId
    ) {
    }

    public function handle(BroadcastService $broadcast): void
    {
        try {
            $broadcast->sendEpisode($this->episode, $this->chatId);
        } catch (TelegramException $e) {

            // Penolakan ini tidak akan berubah kalau dicoba lagi. Mengulang
            // hanya membuat job memenuhi antrean sampai `tries` habis, lalu
            // berakhir di failed_jobs seolah ada yang perlu diperbaiki.
            if ($e->isBlockedByUser() || $e->isChatNotFound()) {

                Log::info('Pengumuman episode dilewati', $e->logContext() + [
                    'episode_id' => $this->episode->id,
                ]);

                return;
            }

            throw $e;
        }
    }
}
