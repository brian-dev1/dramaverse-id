<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'users' => $this['users'],

            'premium_users' => $this['premium_users'],

            'dramas' => $this['dramas'],

            'episodes' => $this['episodes'],

            'subscriptions' => $this['subscriptions'],

        ];
    }
}