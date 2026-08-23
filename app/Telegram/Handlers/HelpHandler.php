<?php

namespace App\Telegram\Handlers;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Telegram\Notice;

class HelpHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    public function handle(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];

        $this->telegram->sendMessage(
            $chatId,
            Notice::make('ℹ️', 'Bantuan')
                ->lead('Selamat datang di DramaVerse ID.')
                ->section('🧭', 'Menu yang tersedia')
                ->bullets([
                    '🔍 Cari Drama — cari judul lewat kata kunci',
                    '🔥 Trending — yang paling banyak ditonton',
                    '🆕 Drama Baru — part yang baru masuk',
                    '❤️ Favorit — daftar simpanan Anda',
                    '👤 Profil — membership, tagihan & Program Affiliate',
                    '🌐 Website — jelajahi katalog lengkap',
                    '💎 Premium — beli atau perpanjang VIP',
                ])
                ->note('Jika mengalami kendala, silakan hubungi admin.')
                ->render()
        );
    }
}