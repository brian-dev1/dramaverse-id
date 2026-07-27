<?php

namespace App\Services;

use App\Models\User;
use App\Models\MembershipPlan;
use App\Repositories\Contracts\MembershipRepositoryInterface;

class MembershipService
{
    public function __construct(
        protected MembershipRepositoryInterface $repository
    ) {
    }

    public function plans()
    {
        return $this->repository->plans();
    }

    public function subscribe(
        User $user,
        MembershipPlan $plan
    ) {
        return $this->repository->subscribe(
            $user,
            $plan
        );
    }

    public function active(User $user)
    {
        return $this->repository->active($user);
    }
}