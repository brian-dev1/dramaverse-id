<?php

namespace App\Telegram\Keyboards;

class HomeKeyboard
{
    public static function make(): array
    {
        return [
            'inline_keyboard' => [

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