<?php

namespace App\Jobs;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu pesan broadcast ke satu pengguna.
 *
 * Sengaja satu job per penerima, bukan satu job untuk semua: Telegram
 * membatasi sekitar 30 pesan per detik, dan bila satu pengiriman gagal
 * (pengguna memblokir bot) sisanya tetap terkirim.
 */
class SendTelegramBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $telegramId,
        public string $message,
        public ?int $userId = null,
    ) {
    }

    public function handle(
        TelegramServiceInterface $telegram,
        TelegramRepositoryInterface $repository
    ): void {

        try {
            $telegram->sendMessage($this->telegramId, $this->message);

            return;
        } catch (TelegramException $e) {

            /*
            |------------------------------------------------------------------
            | Pengguna memblokir bot atau menghapus akunnya
            |------------------------------------------------------------------
            |
            | Ditandai nonaktif supaya tidak terus dicoba di broadcast
            | berikutnya. Sebelum Sprint 8.1 keadaan ini dikenali dengan
            | mencocokkan potongan kata di dalam kalimat galat Telegram —
            | cara yang ikut salah begitu Telegram mengubah kalimatnya, dan
            | akibatnya pengguna yang sudah pergi terus dikirimi selamanya.
            |
            */

            if ($e->isBlockedByUser()) {
                $repository->deactivateByTelegramId($this->telegramId);

                return;
            }

            // Chat tidak ada: tidak ada yang bisa diperbaiki dengan mengulang.
            if ($e->isChatNotFound()) {

                Log::info('Broadcast Telegram dilewati', $e->logContext() + [
                    'telegram_id' => $this->telegramId,
                ]);

                return;
            }

            // Sisanya — batas laju, gangguan jaringan, 5xx — memang pantas
            // diulang. Dilempar lagi supaya antrean yang menjadwalkannya,
            // dengan backoff yang sudah diatur di atas.
            Log::warning('Broadcast Telegram gagal', $e->logContext() + [
                'telegram_id' => $this->telegramId,
            ]);

            throw $e;
        }
    }
}
