<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Telegram\Keyboards\EpisodeKeyboard;

/**
 * Riwayat tontonan, dibaca dari sumber yang sama dengan website.
 */
class HistoryHandler
{
    /** Cukup untuk satu layar ponsel tanpa harus menggulir jauh. */
    private const LIMIT = 10;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected WatchHistoryService $history
    ) {
    }

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        $riwayat = $this->history->latest($user, self::LIMIT);

        if ($riwayat->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "🕒 <b>Riwayat</b>\n\nBelum ada tontonan yang tercatat."
            );

            return;
        }

        $baris = ["🕒 <b>Riwayat</b>\n"];

        $tombol = [];

        foreach ($riwayat as $item) {

            $episode = $item->episode ?? null;

            if ($episode === null) {
                continue;
            }

            $baris[] = sprintf(
                '• %s — episode %s',
                e($episode->drama?->title ?? 'Drama'),
                e((string) $episode->episode_number)
            );

            $tombol[] = [[
                'text'          => mb_substr(
                    ($episode->drama?->title ?? 'Drama').' Ep '.$episode->episode_number,
                    0,
                    60
                ),
                'callback_data' => EpisodeKeyboard::WATCH.':'.$episode->id,
            ]];
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $baris),
            $tombol === [] ? [] : ['reply_markup' => ['inline_keyboard' => $tombol]]
        );
    }
}
