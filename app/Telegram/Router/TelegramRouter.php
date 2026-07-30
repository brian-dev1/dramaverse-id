<?php

namespace App\Telegram\Router;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\UserSessionService;
use App\Telegram\Handlers\CallbackHandler;
use App\Telegram\Handlers\SearchHandler;
use App\Telegram\Handlers\StartHandler;

class TelegramRouter
{
    public function __construct(
        protected UserSessionService $sessions,
        protected TelegramRepositoryInterface $users
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
        |
        | State disimpan dengan id pengguna di basis data kita, BUKAN
        | telegram_id. Sebelum ini `$message['from']['id']` dipakai apa adanya,
        | sehingga pencarian state selalu meleset dan balasan pencarian tidak
        | pernah datang — tanpa satu pun galat, karena mencari baris yang tidak
        | ada memang bukan kesalahan.
        |
        */

        $user = $this->users->findByTelegramId($message['from']['id'] ?? 0);

        if ($user === null) {
            return;
        }

        $state = $this->sessions->current((int) $user->id);

        match ($state) {

            'SEARCH' => app(SearchHandler::class)
                ->handle(
                    $chatId,
                    (int) $user->id,
                    $text
                ),

            default => null,

        };
    }
}
