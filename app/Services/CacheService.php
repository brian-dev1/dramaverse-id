<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    public function remember(
        string $key,
        int $seconds,
        Closure $callback
    ) {
        return Cache::remember(
            $key,
            $seconds,
            $callback
        );
    }

    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }

    public function flush(): bool
    {
        return Cache::flush();
    }

    public function put(
        string $key,
        mixed $value,
        int $seconds
    ): void {
        Cache::put(
            $key,
            $value,
            $seconds
        );
    }

    public function get(
        string $key,
        mixed $default = null
    ) {
        return Cache::get(
            $key,
            $default
        );
    }
}