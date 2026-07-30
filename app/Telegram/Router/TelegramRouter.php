<?php

namespace App\Telegram\Router;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\UserSessionService;
use App\Telegram\Handlers\CallbackHandler;
use App\Telegram\Handlers\SearchHandler;
use App\Telegram\Handlers\StartHandler;
use Illuminate\Support\Facades\Log;

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

            /*
            |------------------------------------------------------------------
            | Update yang tidak ditangani
            |------------------------------------------------------------------
            |
            | Sebelumnya dibuang tanpa jejak. Dicatat sekarang karena satu
            | jenisnya justru dibutuhkan operator: `channel_post` datang setiap
            | kali ada pesan di channel tempat bot jadi admin, dan di dalamnya
            | ada id channel itu — nilai yang harus diisikan ke
            | TELEGRAM_STORAGE_CHAT_ID dan yang tidak terbaca dari mana pun
            | selain dari sini.
            |
            | Isi pesannya TIDAK dicatat. Yang dicatat hanya jenis update,
            | id chat, dan judulnya.
            |
            */

            $this->logUnhandled($update);

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

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Catat update yang tidak ditangani, tanpa isinya.
     *
     * Yang paling berguna: `channel_post`. Kirim satu pesan ke channel
     * penyimpanan, lalu baris ini akan memuat id channelnya —
     * satu-satunya cara membacanya tanpa alat bantu di luar sistem sendiri.
     */
    private function logUnhandled(array $update): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        $jenis = collect(array_keys($update))
            ->reject(fn ($k) => $k === 'update_id')
            ->first() ?? 'tidak dikenal';

        $chat = $update[$jenis]['chat'] ?? [];

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.update.unhandled', [
                'jenis'     => $jenis,
                'chat_id'   => $chat['id'] ?? null,
                'chat_type' => $chat['type'] ?? null,
                'chat_name' => $chat['title'] ?? ($chat['username'] ?? null),
            ]);
    }
}
