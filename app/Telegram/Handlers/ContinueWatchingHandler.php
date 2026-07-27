<?php

namespace App\Telegram\Handlers;

use App\Telegram\Services\TelegramService;

class ContinueWatchingHandler
{
    public function __construct(
        protected TelegramService $telegram
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
            "▶️ <b>Continue Watching</b>\n\nBelum ada riwayat tontonan yang bisa dilanjutkan."
        );
    }
}