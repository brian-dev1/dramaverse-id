<?php

namespace App\Telegram\Handlers;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserService;
use App\Support\TelegramDeepLink;
use App\Telegram\Keyboards\HomeKeyboard;

/**
 * `/start`, dengan atau tanpa deep link.
 *
 * Urutannya penting: akun disinkronkan LEBIH DULU, sebelum parameter deep
 * link diproses. Tautan menonton yang dibuka orang yang belum pernah membuka
 * bot akan sampai di sini sebagai pengguna yang belum ada di basis data kita,
 * dan pemeriksaan membership tanpa pengguna selalu menjawab "tidak boleh".
 * Menyinkronkan belakangan berarti pemilik langganan premium ditolak pada
 * klik pertamanya.
 */
class StartHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserService $userService,
        protected TelegramRepositoryInterface $users
    ) {
    }

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'];

        $this->userService->syncTelegramUser($message['from']);

        $user = $this->users->findByTelegramId($message['from']['id'] ?? 0);

        $parameter = $this->parameter(trim($message['text'] ?? ''));

        /*
        |----------------------------------------------------------------------
        | Deep Link
        |----------------------------------------------------------------------
        */

        if ($parameter !== '') {

            if ($parameter === TelegramDeepLink::SUBSCRIBE) {

                app(PremiumHandler::class)->handle(
                    ['message' => ['chat' => $message['chat']], 'id' => null],
                    $user
                );

                return;
            }

            if ($episodeId = TelegramDeepLink::episodeId($parameter)) {
                app(WatchHandler::class)->handle($chatId, $user, $episodeId);

                return;
            }

            if ($dramaId = TelegramDeepLink::dramaId($parameter)) {
                app(EpisodeListHandler::class)->handle($chatId, $dramaId);

                return;
            }

            // Parameter yang tidak dikenal TIDAK dibiarkan diam. Tautan lama
            // atau salah ketik harus dijawab, lalu dilanjutkan ke menu —
            // bukan berakhir dengan layar kosong.
            $this->telegram->sendMessage(
                $chatId,
                'Tautan yang Anda buka tidak dikenali. Berikut menu utamanya.'
            );
        }

        $this->home($chatId);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /** Ambil bagian setelah `/start`, kosong bila tidak ada. */
    private function parameter(string $text): string
    {
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/', $text, $cocok)) {
            return trim($cocok[1] ?? '');
        }

        return '';
    }

    private function home(int|string $chatId): void
    {
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
