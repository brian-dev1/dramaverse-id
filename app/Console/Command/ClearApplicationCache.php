<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CacheService;

class ClearApplicationCache extends Command
{
    protected $signature = 'cache:application';

    protected $description = 'Clear DramaVerse cache';

    public function __construct(
        protected CacheService $cache
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->cache->flush();

        $this->info('Application cache cleared.');

        return self::SUCCESS;
    }
}