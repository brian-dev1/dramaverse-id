<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class HistoryHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    public function handle(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];

        $this->telegram->answerCallbackQuery(
            $callback['id']
        );

        $this->telegram->sendMessage(
            $chatId,
            "🕒 <b>Riwayat</b>\n\nBelum ada riwayat tontonan."
        );
    }
}