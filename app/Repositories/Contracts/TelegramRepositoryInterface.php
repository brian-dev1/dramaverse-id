<?php

namespace App\Repositories\Contracts;

interface TelegramRepositoryInterface
{
    public function sendMessage(
        string $chatId,
        string $message,
        array $options = []
    );

    public function sendPhoto(
        string $chatId,
        string $photo,
        string $caption = '',
        array $options = []
    );
}