<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(
        protected UserRepository $users
    ) {}

    public function syncTelegramUser(array $telegramUser)
    {
        $user = $this->users->findByTelegramId($telegramUser['id']);

        $name = trim(
            ($telegramUser['first_name'] ?? '') . ' ' . ($telegramUser['last_name'] ?? '')
        );

        if ($name === '') {
            $name = $telegramUser['username'] ?? ('Telegram User ' . $telegramUser['id']);
        }

        if (!$user) {

            return $this->users->create([
                'name' => $name,
                'email' => 'telegram_' . $telegramUser['id'] . '@dramaverse.local',
                'password' => Hash::make(Str::random(32)),
                'telegram_id' => $telegramUser['id'],
                'telegram_username' => $telegramUser['username'] ?? null,
                'telegram_first_name' => $telegramUser['first_name'],
                'telegram_last_name' => $telegramUser['last_name'] ?? null,
                'telegram_language' => $telegramUser['language_code'] ?? null,
                'last_login_at' => now(),
            ]);
        }

        return $this->users->update($user, [

            'name' => $name,

            'telegram_username' => $telegramUser['username'] ?? null,

            'telegram_first_name' => $telegramUser['first_name'],

            'telegram_last_name' => $telegramUser['last_name'] ?? null,

            'telegram_language' => $telegramUser['language_code'] ?? null,

            'last_login_at' => now(),
        ]);
    }
}