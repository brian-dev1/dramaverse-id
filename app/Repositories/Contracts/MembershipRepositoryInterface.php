<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\MembershipPlan;

interface MembershipRepositoryInterface
{
    public function plans();

    public function subscribe(
        User $user,
        MembershipPlan $plan
    );

    public function active(User $user);
}