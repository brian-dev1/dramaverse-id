<?php

namespace App\Telegram\Handlers;

use App\Services\DramaService;
use App\Services\TelegramService;
use App\Services\UserSessionService;

class SearchHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected UserSessionService $session,
        protected DramaService $dramas
    ) {
    }

    public function start(int $chatId, int $userId): void
    {
        $this->session->set($userId, 'SEARCH');

        $this->telegram->sendMessage(
            $chatId,
            "🔎 <b>Cari Drama</b>\n\nSilakan ketik judul drama yang ingin kamu cari."
        );
    }

    public function handle(int $chatId, int $userId, string $keyword): void
    {
        $results = $this->dramas->search($keyword);

        $this->session->clear($userId);

        if ($results->isEmpty()) {

            $this->telegram->sendMessage(
                $chatId,
                "❌ Drama tidak ditemukan."
            );

            return;
        }

        $text = "🎬 <b>Hasil Pencarian</b>\n\n";

        foreach ($results as $drama) {

            $text .= "• {$drama->title}\n";

        }

        $this->telegram->sendMessage(
            $chatId,
            $text
        );
    }
}