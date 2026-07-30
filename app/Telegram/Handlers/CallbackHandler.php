<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;

class CallbackHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    public function handle(array $callback): void
    {
        // Konfirmasi ke Telegram SEGERA agar tombol tidak loading/delay
        // menunggu proses handler di bawah selesai.
        $this->telegram->answerCallbackQuery($callback['id']);

        $data = $callback['data'] ?? '';

        match ($data) {

            // SearchHandler menerima chat dan user secara terpisah, bukan
            // seluruh array callback: state percakapan disimpan per PENGGUNA
            // (`from.id`), sedangkan balasannya dikirim ke CHAT (`chat.id`).
            // Di chat pribadi keduanya sama, di grup tidak.
            'search'
                => app(SearchHandler::class)->start(
                    (int) $callback['message']['chat']['id'],
                    (int) $callback['from']['id'],
                ),

            'continue'
                => app(ContinueWatchingHandler::class)->handle($callback),

            'favorite'
                => app(FavoriteHandler::class)->handle($callback),

            'history'
                => app(HistoryHandler::class)->handle($callback),

            'profile'
                => app(ProfileHandler::class)->handle($callback),

            'premium'
                => app(PremiumHandler::class)->handle($callback),

            'website'
                => app(WebsiteHandler::class)->handle($callback),

            'help'
                => app(HelpHandler::class)->handle($callback),

            default
                => app(StartHandler::class)->handle([
                    'chat' => $callback['message']['chat'],
                    'from' => $callback['from'],
                    'text' => '/start',
                ]),
        };
    }
}