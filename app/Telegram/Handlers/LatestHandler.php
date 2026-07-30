<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class LatestHandler
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
            "🆕 <b>Drama Baru</b>\n\nFitur ini sedang dalam pengembangan."
        );
    }
}