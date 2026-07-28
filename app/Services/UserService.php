<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;

class UserService
{
    public function __construct(
        protected UserRepository $users
    ) {
    }

    /**
     * Menyelaraskan profil Telegram ke tabel users.
     *
     * Pengguna biasa tidak punya email maupun kata sandi — identitasnya
     * sepenuhnya bersandar pada telegram_id. Kolom email/password hanya
     * dipakai akun admin.
     */
    public function syncTelegramUser(array $telegramUser): User
    {
        $telegramId = (int) $telegramUser['id'];

        $attributes = [
            'name'                => $this->resolveName($telegramUser),
            'telegram_username'   => $telegramUser['username'] ?? null,
            'telegram_first_name' => $telegramUser['first_name'] ?? null,
            'telegram_last_name'  => $telegramUser['last_name'] ?? null,
            'telegram_language'   => $telegramUser['language_code'] ?? null,
            'last_seen_at'        => now(),
        ];

        $user = $this->users->findByTelegramId($telegramId);

        if (! $user) {
            return $this->users->create($attributes + [
                'telegram_id' => $telegramId,
                'is_admin'    => false,
                'is_active'   => true,
            ]);
        }

        return $this->users->update($user, $attributes);
    }

    private function resolveName(array $telegramUser): string
    {
        $name = trim(
            ($telegramUser['first_name'] ?? '').' '.($telegramUser['last_name'] ?? '')
        );

        if ($name !== '') {
            return $name;
        }

        return $telegramUser['username'] ?? 'Pengguna Telegram '.$telegramUser['id'];
    }
}
