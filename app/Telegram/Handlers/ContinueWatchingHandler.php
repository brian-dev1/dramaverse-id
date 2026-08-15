<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Telegram\Keyboards\EpisodeKeyboard;

/**
 * "Lanjut menonton" — episode terakhir yang ditonton pengguna.
 *
 * Dibaca dari `WatchHistoryService`, tabel yang sama dengan yang dipakai
 * website. Episode yang baru ditonton di laptop akan langsung muncul di sini,
 * dan episode yang ditonton lewat bot akan muncul di halaman profil — tanpa
 * ada proses sinkronisasi di antara keduanya, karena tidak ada dua salinan
 * data yang perlu disamakan.
 */
class ContinueWatchingHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected WatchHistoryService $history
    ) {
    }

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        $terakhir = $this->history->latest($user, 1)->first();

        $episode = $terakhir?->episode;

        if ($episode === null) {
            $this->telegram->sendMessage(
                $chatId,
                "▶️ <b>Lanjut Menonton</b>\n\nBelum ada riwayat tontonan. "
                .'Pilih drama lewat tombol Cari untuk memulai.'
            );

            return;
        }

        // Yang ditawarkan adalah episode BERIKUTNYA bila ada — itu yang
        // dimaksud "lanjut". Kalau sudah episode terakhir, yang ditawarkan
        // adalah episode itu sendiri untuk ditonton ulang.
        $lanjut = $episode->next() ?? $episode;

        $lanjutan = $lanjut->id !== $episode->id;

        $this->telegram->sendMessage(
            $chatId,
            sprintf(
                "▶️ <b>Lanjut Menonton</b>\n\n%s\nTerakhir: episode %s\n%s",
                e($episode->drama?->title ?? 'Drama'),
                e((string) $episode->episode_number),
                $lanjutan
                    ? 'Lanjutkan ke part '.e((string) $lanjut->episode_number).'.'
                    : 'Ini part terakhir yang tersedia.'
            ),
            [
                'reply_markup' => ['inline_keyboard' => [
                    [[
                        'text'          => ($lanjutan ? 'Tonton part ' : 'Tonton ulang part ')
                                            .$lanjut->episode_number,
                        'callback_data' => EpisodeKeyboard::WATCH.':'.$lanjut->id,
                    ]],
                    [[
                        'text'          => '📑 Daftar Part',
                        'callback_data' => EpisodeKeyboard::LIST.':'.$episode->drama_id.':1',
                    ]],
                ]],
            ]
        );
    }
}
