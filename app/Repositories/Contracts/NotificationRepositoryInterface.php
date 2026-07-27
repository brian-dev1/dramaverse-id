<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Collection;

interface NotificationRepositoryInterface
{
    public function all(
        User $user,
        int $limit = 50
    ): Collection;

    public function unread(
        User $user
    ): Collection;

    public function create(
        array $data
    ): Notification;

    public function markAsRead(
        Notification $notification
    ): Notification;

    public function markAllAsRead(
        User $user
    ): int;
}