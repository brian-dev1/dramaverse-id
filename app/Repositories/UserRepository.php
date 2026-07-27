<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function findByTelegramId(int $telegramId): ?User
    {
        return User::where('telegram_id', $telegramId)->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->refresh();
    }
}