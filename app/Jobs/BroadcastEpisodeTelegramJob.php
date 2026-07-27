<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastEpisodeTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        protected Episode $episode,
        protected string $chatId
    ) {
    }

    public function handle(
        TelegramService $telegram
    ): void {
        $telegram->broadcastEpisode(
            $this->chatId,
            $this->episode
        );
    }
}