<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\LoginTokenRepository;
use Illuminate\Support\Str;

class LoginService
{
    public function __construct(
        protected LoginTokenRepository $tokens
    ) {}

    public function generate(User $user): string
    {
        $plain = Str::random(64);

        $this->tokens->create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(config('telegram.login_token_ttl', 10)),
        ]);

        return $plain;
    }
}