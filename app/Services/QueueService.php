<?php

namespace App\Services;

use App\Jobs\BroadcastEpisodeJob;
use App\Jobs\GenerateVideoThumbnailJob;
use App\Jobs\SendPremiumReminderJob;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Episode;
use App\Models\User;

class QueueService
{
    public function thumbnail(Episode $episode): void
    {
        GenerateVideoThumbnailJob::dispatch($episode);
    }

    public function broadcast(Episode $episode): void
    {
        BroadcastEpisodeJob::dispatch($episode);
    }

    public function telegram(string $message): void
    {
        SendTelegramNotificationJob::dispatch($message);
    }

    public function premium(User $user): void
    {
        SendPremiumReminderJob::dispatch($user);
    }
}