<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Repositories\LoginTokenRepository;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    public function __construct(
        protected LoginTokenRepository $tokens
    ) {
    }

    public function __invoke(string $token)
    {
        $loginToken = $this->tokens->find($token);

        if (!$loginToken) {
            abort(403, 'Token tidak valid atau telah kedaluwarsa.');
        }

        Auth::login($loginToken->user);

        $this->tokens->markAsUsed($loginToken);

        return redirect()->route('dashboard');
    }
}