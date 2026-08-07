<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use App\Support\TelegramInitData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Login otomatis ketika website dibuka sebagai Telegram Mini App.
 *
 * Halaman mini app mengirim `initData` mentah dari Telegram.WebApp,
 * lalu di sini tanda tangannya diverifikasi dan sesi login dibuat.
 */
class TelegramMiniAppController extends Controller
{
    public function __construct(
        protected UserService $users
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (Auth::check()) {
            return response()->json(['ok' => true, 'already' => true]);
        }

        $initData = (string) $request->input('init_data', '');

        $payload = TelegramInitData::validate(
            $initData,
            null,
            (int) config('telegram.miniapp_auth_ttl', 86400)
        );

        if (! $payload) {
            // Dicatat supaya penyebabnya bisa dibedakan: bot token salah,
            // initData kosong, atau tanda tangan tidak cocok.
            Log::warning('telegram.miniapp.invalid', [
                'panjang_init_data' => strlen($initData),
                'ada_bot_token'     => config('telegram.bot_token') ? 'ya' : 'tidak',
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Data Telegram tidak valid atau sudah kedaluwarsa.',
            ], 403);
        }

        $user = $this->users->syncTelegramUser($payload['user']);

        if ($user->is_banned || ! $user->is_active) {
            return response()->json([
                'ok'      => false,
                'message' => 'Akun Anda tidak aktif. Hubungi admin.',
            ], 403);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'ok'          => true,
            'name'        => $user->display_name,
            'start_param' => $payload['start_param'],
        ]);
    }
}
