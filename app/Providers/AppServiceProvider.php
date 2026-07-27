<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Repositories\DramaRepository;
use App\Repositories\EpisodeRepository;
use App\Repositories\HomeRepository;
use App\Repositories\ProgressRepository;
use App\Repositories\WatchHistoryRepository;
use App\Repositories\NotificationRepository;

use App\Repositories\Contracts\DramaRepositoryInterface;
use App\Repositories\Contracts\EpisodeRepositoryInterface;
use App\Repositories\Contracts\HomeRepositoryInterface;
use App\Repositories\Contracts\ProgressRepositoryInterface;
use App\Repositories\Contracts\WatchHistoryRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            DramaRepositoryInterface::class,
            DramaRepository::class
        );

        $this->app->bind(
            EpisodeRepositoryInterface::class,
            EpisodeRepository::class
        );

        $this->app->bind(
            HomeRepositoryInterface::class,
            HomeRepository::class
        );

        $this->app->bind(
            WatchHistoryRepositoryInterface::class,
            WatchHistoryRepository::class
        );

        $this->app->bind(
            ProgressRepositoryInterface::class,
            ProgressRepository::class
        );

        $this->app->bind(
            NotificationRepositoryInterface::class,
            NotificationRepository::class
        );
    }

    public function boot(): void
    {
        //
    }
}