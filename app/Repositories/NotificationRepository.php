<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\NotificationRepositoryInterface;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function all(
        User $user,
        int $limit = 50
    ): Collection {

        return Notification::query()

            ->where(
                'user_id',
                $user->id
            )

            ->latest()

            ->limit($limit)

            ->get();

    }

    public function unread(
        User $user
    ): Collection {

        return Notification::query()

            ->where(
                'user_id',
                $user->id
            )

            ->whereNull(
                'read_at'
            )

            ->latest()

            ->get();

    }

    public function create(
        array $data
    ): Notification {

        return Notification::create(
            $data
        );

    }

    public function markAsRead(
        Notification $notification
    ): Notification {

        $notification->update([

            'read_at' => now(),

        ]);

        return $notification;

    }

    public function markAllAsRead(
        User $user
    ): int {

        return Notification::query()

            ->where(
                'user_id',
                $user->id
            )

            ->whereNull(
                'read_at'
            )

            ->update([

                'read_at' => now(),

            ]);

    }
}