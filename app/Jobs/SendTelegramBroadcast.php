<?php

namespace App\Jobs;

use App\Models\User;
use App\Telegram\Services\TelegramService;
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

    public function handle(TelegramService $telegram): void
    {
        $result = $telegram->sendMessage($this->telegramId, $this->message);

        if (($result['ok'] ?? false) === true) {
            return;
        }

        $description = $result['description'] ?? 'tidak diketahui';

        // Pengguna memblokir bot atau menghapus akun — tandai nonaktif
        // supaya tidak terus dicoba di broadcast berikutnya.
        if (str_contains($description, 'blocked') || str_contains($description, 'user is deactivated')) {
            User::where('telegram_id', $this->telegramId)->update(['is_active' => false]);

            return;
        }

        Log::warning('Broadcast Telegram gagal', [
            'telegram_id' => $this->telegramId,
            'alasan'      => $description,
        ]);
    }
}
