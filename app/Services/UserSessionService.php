<?php

namespace App\Services;

use App\Repositories\UserSessionRepository;

class UserSessionService
{
    public function __construct(
        protected UserSessionRepository $sessions
    ) {
    }

    public function current(int $userId): ?string
    {
        return $this->sessions
            ->get($userId)
            ?->state;
    }

    public function set(
        int $userId,
        string $state,
        array $payload = []
    ): void {

        $this->sessions->set(
            $userId,
            $state,
            $payload
        );
    }

    public function clear(int $userId): void
    {
        $this->sessions->clear($userId);
    }
}