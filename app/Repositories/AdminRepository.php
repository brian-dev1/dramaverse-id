<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\Subscription;
use App\Repositories\Contracts\AdminRepositoryInterface;

class AdminRepository implements AdminRepositoryInterface
{
    public function dashboard(): array
    {
        return [

            'users' => User::count(),

            'premium_users' => User::where('is_premium', true)->count(),

            'dramas' => Drama::count(),

            'episodes' => Episode::count(),

            'subscriptions' => Subscription::count(),

        ];
    }
}