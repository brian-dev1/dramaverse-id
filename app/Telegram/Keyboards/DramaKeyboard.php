<?php

namespace App\Telegram\Keyboards;

use Illuminate\Support\Collection;

class DramaKeyboard
{
    public static function searchResult(Collection $dramas): array
    {
        $keyboard = [];

        foreach ($dramas as $drama) {

            $keyboard[] = [[

                'text' => '🎬 '.$drama->title,

                'callback_data' => 'drama.view.'.$drama->id,

            ]];

        }

        $keyboard[] = [[

            'text' => '🏠 Menu Utama',

            'callback_data' => 'home',

        ]];

        return [

            'inline_keyboard' => $keyboard,

        ];
    }
}