<?php

namespace App\Telegram\Services;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.telegram.org/bot' . config('telegram.bot_token');
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        array $options = []
    ): array {

        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $options);

        if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
            $payload['reply_markup'] = json_encode($payload['reply_markup']);
        }

        return Http::post(
            $this->baseUrl . '/sendMessage',
            $payload
        )->json();
    }

    public function answerCallbackQuery(
        string $callbackQueryId,
        string $text = ''
    ): array {

        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
        }

        return Http::post(
            $this->baseUrl . '/answerCallbackQuery',
            $payload
        )->json();
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        array $options = []
    ): array {

        $payload = array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $options);

        if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
            $payload['reply_markup'] = json_encode($payload['reply_markup']);
        }

        return Http::post(
            $this->baseUrl . '/editMessageText',
            $payload
        )->json();
    }

    public function deleteMessage(
        int|string $chatId,
        int $messageId
    ): array {

        return Http::post(
            $this->baseUrl . '/deleteMessage',
            [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]
        )->json();
    }
}