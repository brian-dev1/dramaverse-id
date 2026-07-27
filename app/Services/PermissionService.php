<?php

namespace App\Services;

use App\Models\User;

class PermissionService
{
    public function can(
        User $user,
        string $permission
    ): bool
    {
        return $user->roles()

            ->whereHas('permissions', function ($query) use ($permission) {

                $query->where(

                    'slug',

                    $permission

                );

            })

            ->exists();
    }
}