<?php

namespace App\Telegram\Handlers;

use App\Models\Episode;
use App\Models\User;
use App\Services\Telegram\ChannelGate;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\TelegramDeliveryService;

/**
 * Menangani permintaan menonton, baik dari deep link maupun dari tombol.
 *
 * Dua jalan masuk, satu jalur keluar. Deep link dan tombol Next/Previous
 * menghasilkan permintaan yang isinya sama persis — id episode dan siapa
 * yang meminta — jadi keduanya bertemu di method yang sama. Menulisnya dua
 * kali berarti aturan membership perlu diperiksa di dua tempat, dan yang
 * satu pasti akan tertinggal.
 */
class WatchHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected TelegramDeliveryService $delivery,
        protected ChannelGate $channel
    ) {
    }

    /**
     * @param  int  $episodeId  id dari deep link atau dari callback_data —
     *                          keduanya masukan dari luar, jadi keduanya
     *                          diperlakukan sebagai belum tentu ada
     * @param  int|null  $gantiPesanId  pesan video yang harus lenyap setelah
     *                          penggantinya terkirim. Null untuk deep link
     *                          dan menu — di situ tidak ada video yang sedang
     *                          ditonton, jadi tidak ada yang perlu diganti.
     */
    public function handle(
        int|string $chatId,
        ?User $user,
        int $episodeId,
        ?int $gantiPesanId = null
    ): void {
        $episode = Episode::with('drama', 'video')->find($episodeId);

        if ($episode === null) {

            // Tautan lama yang episodenya sudah dihapus, atau id yang
            // dikarang orang. Keduanya bukan kesalahan sistem, dan keduanya
            // pantas mendapat jawaban yang jelas.
            $this->telegram->sendMessage(
                $chatId,
                'Part yang Anda buka tidak ditemukan. Tautannya mungkin sudah '
                .'kedaluwarsa, atau partnya sudah dihapus.'
            );

            return;
        }

        /*
        |----------------------------------------------------------------------
        | Gabung channel dulu
        |----------------------------------------------------------------------
        |
        | Diperiksa SESUDAH episodenya dipastikan ada. Urutan ini disengaja:
        | menahan orang di gerbang channel untuk episode yang ternyata sudah
        | dihapus berarti ia bergabung lebih dulu, lalu tetap tidak mendapat
        | apa-apa — dan pada saat itu ia sudah menyalahkan channelnya.
        |
        */

        if (! $this->channel->lolos($user)) {

            [$pesan, $opsi] = $this->channel->penahan('menonton');

            $this->telegram->sendMessage($chatId, $pesan, $opsi);

            return;
        }

        $this->delivery->send($chatId, $user, $episode, $gantiPesanId);
    }
}
