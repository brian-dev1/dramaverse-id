<?php

namespace App\Services;

use App\Models\Episode;
use App\Repositories\Contracts\TelegramRepositoryInterface;

class TelegramService
{
    public function __construct(
        protected TelegramRepositoryInterface $repository
    ) {
    }

    public function sendText(
        string $chatId,
        string $message
    ) {
        return $this->repository->sendMessage(
            $chatId,
            $message
        );
    }

    public function broadcastEpisode(
        string $chatId,
        Episode $episode
    ) {
        $caption = sprintf(
            "🎬 <b>%s</b>\nEpisode %s telah tersedia.",
            $episode->drama->title,
            $episode->episode_number
        );

        return $this->repository->sendPhoto(
            $chatId,
            $episode->thumbnail,
            $caption
        );
    }
}