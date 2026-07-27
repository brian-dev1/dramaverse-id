<?php

namespace App\Repositories\Contracts;

interface EpisodeSchedulerRepositoryInterface
{
    public function publishScheduled();

    public function expireEpisodes();
}