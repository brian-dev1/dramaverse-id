<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Episode;

class WebTelegramController extends Controller
{
    public function redirectEpisode(Episode $episode)
    {
        if (!$episode->telegram_parameter) {
            abort(404);
        }

        return redirect()->away(

            "https://t.me/DracinHubBot?start={$episode->telegram_parameter}"

        );
    }
}