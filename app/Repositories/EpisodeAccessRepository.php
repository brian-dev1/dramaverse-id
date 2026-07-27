<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Episode;
use App\Repositories\Contracts\EpisodeAccessRepositoryInterface;

class EpisodeAccessRepository implements EpisodeAccessRepositoryInterface
{
    public function canWatch(
        ?User $user,
        Episode $episode
    ): bool
    {
        if ($episode->access_type === 'free') {
            return true;
        }

        if (!$user) {
            return false;
        }

        return $user->is_premium
            && (
                !$user->premium_expired_at ||
                now()->lt($user->premium_expired_at)
            );
    }
}