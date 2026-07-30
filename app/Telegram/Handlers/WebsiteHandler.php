<?php

namespace App\Telegram\Handlers;

use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;

class WebsiteHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserRepository $users,
        protected LoginService $login
    ) {
    }

    public function handle(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];
        $telegramId = $callback['from']['id'];

        $user = $this->users->findByTelegramId($telegramId);

        if (!$user) {

            $this->telegram->answerCallbackQuery(
                $callback['id'],
                'Silakan kirim /start terlebih dahulu.'
            );

            return;
        }

        $token = $this->login->generate($user);

        $minutes = (int) config('telegram.login_token_ttl', 10);

        $url = url('/auth/telegram/' . $token);

        $this->telegram->answerCallbackQuery(
            $callback['id']
        );

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", [
                "🌐 *DramaVerse Website*",
                "",
                "Klik link di bawah untuk login otomatis ke website.",
                "",
                $url,
                "",
                "⏳ Link hanya berlaku selama {$minutes} menit dan sekali pakai."
            ]),
            [
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]
        );
    }
}