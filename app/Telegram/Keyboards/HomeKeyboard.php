<?php

namespace App\Telegram\Keyboards;

class HomeKeyboard
{
    public static function make(): array
    {
        return [
            'inline_keyboard' => [

                // Cari ditaruh paling atas: SearchHandler sudah ada sejak awal
                // dan TelegramRouter sudah menangani state SEARCH, tapi tidak
                // pernah ada tombol yang memulainya — jadi seluruh alur
                // pencarian tidak bisa dijangkau pengguna sama sekali.
                [
                    [
                        'text' => '🔎 Cari Drama',
                        'callback_data' => 'search'
                    ]
                ],

                [
                    [
                        'text' => '▶️ Continue Watching',
                        'callback_data' => 'continue'
                    ]
                ],

                [
                    [
                        'text' => '❤️ Favorit',
                        'callback_data' => 'favorite'
                    ],
                    [
                        'text' => '🕒 Riwayat',
                        'callback_data' => 'history'
                    ]
                ],

                [
                    [
                        'text' => '🌐 Buka Website',
                        'callback_data' => 'website'
                    ]
                ],

                [
                    [
                        'text' => '💎 Premium',
                        'callback_data' => 'premium'
                    ],
                    [
                        'text' => '👤 Profil',
                        'callback_data' => 'profile'
                    ]
                ],

                [
                    [
                        'text' => '⚙️ Bantuan',
                        'callback_data' => 'help'
                    ]
                ],

            ],
        ];
    }
}