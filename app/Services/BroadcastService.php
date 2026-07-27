<?php

namespace App\Services;

use App\Jobs\BroadcastEpisodeTelegramJob;
use App\Models\Episode;

class BroadcastService
{
    public function episode(
        Episode $episode,
        string $chatId
    ): void {
        BroadcastEpisodeTelegramJob::dispatch(
            $episode,
            $chatId
        );
    }
}