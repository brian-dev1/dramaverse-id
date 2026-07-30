<?php

namespace App\Telegram\Handlers;

use App\Models\Episode;
use App\Models\User;
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
        protected TelegramDeliveryService $delivery
    ) {
    }

    /**
     * @param  int  $episodeId  id dari deep link atau dari callback_data —
     *                          keduanya masukan dari luar, jadi keduanya
     *                          diperlakukan sebagai belum tentu ada
     */
    public function handle(int|string $chatId, ?User $user, int $episodeId): void
    {
        $episode = Episode::with('drama', 'video')->find($episodeId);

        if ($episode === null) {

            // Tautan lama yang episodenya sudah dihapus, atau id yang
            // dikarang orang. Keduanya bukan kesalahan sistem, dan keduanya
            // pantas mendapat jawaban yang jelas.
            $this->telegram->sendMessage(
                $chatId,
                'Episode yang Anda buka tidak ditemukan. Tautannya mungkin sudah '
                .'kedaluwarsa, atau episodenya sudah dihapus.'
            );

            return;
        }

        $this->delivery->send($chatId, $user, $episode);
    }
}
