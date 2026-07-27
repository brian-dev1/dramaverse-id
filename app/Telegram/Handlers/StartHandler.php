<?php

namespace App\Telegram\Handlers;

use App\Services\UserService;
use App\Telegram\Keyboards\HomeKeyboard;
use App\Telegram\Services\TelegramService;

class StartHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected UserService $userService
    ) {
    }

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'];

        // Sinkronisasi akun Telegram ke database
        $this->userService->syncTelegramUser($message['from']);

        $text = trim($message['text'] ?? '');

        // Ambil parameter setelah /start
        $parameter = '';

        if (preg_match('/^\/start(?:\s+(.+))?$/', $text, $matches)) {
            $parameter = trim($matches[1] ?? '');
        }

        /*
        |--------------------------------------------------------------------------
        | Deep Link (persiapan Website → Telegram)
        |--------------------------------------------------------------------------
        */

        if ($parameter !== '') {

            // Episode
            if (str_starts_with($parameter, 'episode_')) {

                $episodeId = (int) str_replace('episode_', '', $parameter);

                $this->telegram->sendMessage(
                    $chatId,
                    "🎬 <b>Membuka Episode</b>\n\nEpisode ID : <b>{$episodeId}</b>\n\n⏳ Fitur player sedang dalam proses pengembangan."
                );

                return;
            }

            // Drama
            if (str_starts_with($parameter, 'drama_')) {

                $dramaId = (int) str_replace('drama_', '', $parameter);

                $this->telegram->sendMessage(
                    $chatId,
                    "🎭 <b>Membuka Drama</b>\n\nDrama ID : <b>{$dramaId}</b>\n\n⏳ Detail drama akan segera tersedia."
                );

                return;
            }

            // Continue Watching
            if ($parameter === 'continue') {

                $this->telegram->sendMessage(
                    $chatId,
                    "▶ <b>Continue Watching</b>\n\nFitur ini sedang dipersiapkan."
                );

                return;
            }

            // Favorite
            if ($parameter === 'favorite') {

                $this->telegram->sendMessage(
                    $chatId,
                    "❤️ <b>Favorite</b>\n\nFitur ini sedang dipersiapkan."
                );

                return;
            }

            // Premium
            if ($parameter === 'premium') {

                $this->telegram->sendMessage(
                    $chatId,
                    "💎 <b>Premium</b>\n\nFitur ini sedang dipersiapkan."
                );

                return;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Home
        |--------------------------------------------------------------------------
        */

        $welcome = <<<HTML
🎭 <b>DramaVerse ID</b>

Selamat datang di <b>DramaVerse ID</b>.

Website digunakan untuk mencari drama dan memilih episode.

Telegram digunakan sebagai media untuk menonton drama.

Silakan pilih menu di bawah ini.
HTML;

        $this->telegram->sendMessage(
            $chatId,
            $welcome,
            ['reply_markup' => HomeKeyboard::make()]
        );
    }
}