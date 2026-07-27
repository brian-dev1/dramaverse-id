<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\NotificationRepositoryInterface;

class NotificationService
{
    public function __construct(
        protected NotificationRepositoryInterface $repository
    ) {
    }

    public function all(
        User $user,
        int $limit = 50
    ): Collection {

        return $this->repository->all(
            $user,
            $limit
        );

    }

    public function unread(
        User $user
    ): Collection {

        return $this->repository->unread(
            $user
        );

    }

    public function create(
        array $data
    ): Notification {

        return $this->repository->create(
            $data
        );

    }

    public function markAsRead(
        Notification $notification
    ): Notification {

        return $this->repository->markAsRead(
            $notification
        );

    }

    public function markAllAsRead(
        User $user
    ): int {

        return $this->repository->markAllAsRead(
            $user
        );

    }
}