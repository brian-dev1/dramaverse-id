<?php

namespace App\Services;

use App\Models\User;

class DashboardService
{
    public function __construct(
        protected HomeService $homeService,
        protected FavoriteService $favoriteService,
        protected WatchHistoryService $watchHistoryService
    ) {
    }

    public function dashboard(User $user): array
    {
        $home = $this->homeService->home($user->id);

        return [

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],

            'trending' => $home['trending'],

            'latest' => $home['latest'],

            'continueWatching' => $home['continueWatching'],

            'favorites' => $this->favoriteService->all($user),

            'history' => $this->watchHistoryService->history($user),

            'stats' => [

                'favorite_count' => $this->favoriteService
                    ->all($user)
                    ->count(),

                'history_count' => $this->watchHistoryService
                    ->history($user)
                    ->count(),

            ],

        ];
    }
}