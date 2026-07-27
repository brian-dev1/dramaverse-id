<?php

namespace App\Telegram\Handlers;

use App\Telegram\Services\TelegramService;

class FavoriteHandler
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
            "❤️ <b>Favorit</b>\n\nBelum ada drama favorit."
        );
    }
}