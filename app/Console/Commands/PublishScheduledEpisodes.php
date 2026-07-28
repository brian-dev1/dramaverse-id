<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EpisodeSchedulerService;

class PublishScheduledEpisodes extends Command
{
    protected $signature = 'episodes:publish';

    protected $description = 'Publish scheduled episodes';

    public function __construct(
        protected EpisodeSchedulerService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->service->run();

        $this->info('Episode scheduler executed.');

        return self::SUCCESS;
    }
}