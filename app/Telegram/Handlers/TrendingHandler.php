<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class TrendingHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    public function handle(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];

        $this->telegram->sendMessage(
            $chatId,
            "🔥 <b>Trending Drama</b>\n\nFitur ini sedang dalam pengembangan."
        );
    }
}