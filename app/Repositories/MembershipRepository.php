<?php

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Subscription;
use App\Models\MembershipPlan;
use App\Repositories\Contracts\MembershipRepositoryInterface;

class MembershipRepository implements MembershipRepositoryInterface
{
    public function plans()
    {
        return MembershipPlan::where('is_active', true)->get();
    }

    public function subscribe(
        User $user,
        MembershipPlan $plan
    ) {

        return Subscription::create([

            'user_id' => $user->id,

            'membership_plan_id' => $plan->id,

            'price' => $plan->price,

            'started_at' => now(),

            'expired_at' => Carbon::now()->addDays($plan->duration),

            'status' => 'active',

        ]);

    }

    public function active(User $user)
    {
        return Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();
    }
}