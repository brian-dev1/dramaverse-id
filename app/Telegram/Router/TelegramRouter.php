<?php

namespace App\Telegram\Router;

use App\Services\UserSessionService;
use App\Telegram\Handlers\CallbackHandler;
use App\Telegram\Handlers\SearchHandler;
use App\Telegram\Handlers\StartHandler;

class TelegramRouter
{
    public function __construct(
        protected UserSessionService $sessions
    ) {
    }

    public function dispatch(array $update): void
    {
        /*
        |--------------------------------------------------------------------------
        | Callback Query
        |--------------------------------------------------------------------------
        */

        if (isset($update['callback_query'])) {

            app(CallbackHandler::class)
                ->handle($update['callback_query']);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        if (! isset($update['message'])) {
            return;
        }

        $message = $update['message'];

        $chatId = $message['chat']['id'];

        $userId = $message['from']['id'];

        $text = trim($message['text'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | START COMMAND
        |--------------------------------------------------------------------------
        */

        if (str_starts_with($text, '/start')) {

            app(StartHandler::class)
                ->handle($message);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Conversation State
        |--------------------------------------------------------------------------
        */

        $state = $this->sessions->current($userId);

        match ($state) {

            'SEARCH' => app(SearchHandler::class)
                ->handle(
                    $chatId,
                    $userId,
                    $text
                ),

            default => null,

        };
    }
}