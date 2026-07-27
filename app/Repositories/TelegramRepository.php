<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Http;
use App\Repositories\Contracts\TelegramRepositoryInterface;

class TelegramRepository implements TelegramRepositoryInterface
{
    protected function token(): string
    {
        return config('services.telegram.bot_token');
    }

    protected function api(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token()}/{$method}";
    }

    public function sendMessage(
        string $chatId,
        string $message,
        array $options = []
    ) {
        return Http::post($this->api('sendMessage'), array_merge([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ], $options))->json();
    }

    public function sendPhoto(
        string $chatId,
        string $photo,
        string $caption = '',
        array $options = []
    ) {
        return Http::post($this->api('sendPhoto'), array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], $options))->json();
    }
}