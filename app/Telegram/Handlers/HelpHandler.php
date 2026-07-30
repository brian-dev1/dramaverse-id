<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class HelpHandler
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
            <<<HTML
ℹ️ <b>Bantuan</b>

Selamat datang di DramaVerse ID.

Menu yang tersedia:

🔍 Cari Drama
🔥 Trending
🆕 Drama Baru
❤️ Favorit
👤 Profil
🌐 Website
💎 Premium

Jika mengalami kendala, silakan hubungi admin.
HTML
        );
    }
}