<?php

namespace App\Traits;

use App\Services\CacheService;

trait HasCache
{
    protected CacheService $cache;

    public function cache(): CacheService
    {
        return app(CacheService::class);
    }
}