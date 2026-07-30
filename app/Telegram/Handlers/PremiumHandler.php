<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class PremiumHandler
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
            "💎 <b>DramaVerse Premium</b>\n\nHalaman Premium sedang disiapkan."
        );
    }
}