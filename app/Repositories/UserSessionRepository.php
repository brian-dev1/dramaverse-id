<?php

namespace App\Repositories;

use App\Models\UserSession;

class UserSessionRepository
{
    public function get(int $userId): ?UserSession
    {
        return UserSession::where('user_id', $userId)->first();
    }

    public function set(
        int $userId,
        string $state,
        array $payload = []
    ): UserSession {

        return UserSession::updateOrCreate(

            [
                'user_id' => $userId,
            ],

            [
                'state' => $state,
                'payload' => $payload,
            ]

        );
    }

    public function clear(int $userId): void
    {
        UserSession::where('user_id', $userId)->delete();
    }
}