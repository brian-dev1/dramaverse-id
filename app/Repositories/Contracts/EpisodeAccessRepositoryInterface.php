<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Episode;

interface EpisodeAccessRepositoryInterface
{
    public function canWatch(
        ?User $user,
        Episode $episode
    ): bool;
}