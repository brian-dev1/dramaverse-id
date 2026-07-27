<?php

namespace App\Telegram\Handlers;

use App\Telegram\Services\TelegramService;

class TrendingHandler
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
            "🔥 <b>Trending Drama</b>\n\nFitur ini sedang dalam pengembangan."
        );
    }
}