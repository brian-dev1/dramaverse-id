<?php

namespace App\Repositories;

use App\Models\LoginToken;

class LoginTokenRepository
{
    public function create(array $data): LoginToken
    {
        return LoginToken::create($data);
    }

    public function find(string $token): ?LoginToken
    {
        return LoginToken::where('token', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markAsUsed(LoginToken $token): void
    {
        $token->update([
            'used_at' => now(),
        ]);
    }
}