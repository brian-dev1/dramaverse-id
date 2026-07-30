<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class ProfileHandler
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
            "👤 <b>Profil</b>\n\nFitur profil akan segera tersedia."
        );
    }
}