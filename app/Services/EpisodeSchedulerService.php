<?php

namespace App\Services;

use App\Repositories\Contracts\EpisodeSchedulerRepositoryInterface;

class EpisodeSchedulerService
{
    public function __construct(
        protected EpisodeSchedulerRepositoryInterface $repository
    ){
    }

    public function run(): void
    {
        $this->repository->publishScheduled();

        $this->repository->expireEpisodes();
    }
}