<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Repositories\LoginTokenRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Menukar token sekali pakai dari bot Telegram menjadi sesi login.
 */
class TelegramAuthController extends Controller
{
    public function __construct(
        protected LoginTokenRepository $tokens
    ) {
    }

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $loginToken = $this->tokens->find($token);

        if (! $loginToken || ! $loginToken->user) {
            abort(403, 'Tautan masuk tidak valid atau sudah kedaluwarsa. Minta tautan baru lewat bot Telegram.');
        }

        $user = $loginToken->user;

        if ($user->is_banned || ! $user->is_active) {
            abort(403, 'Akun Anda tidak aktif. Hubungi admin melalui Telegram.');
        }

        // Tandai terpakai lebih dulu — token sekali pakai tetap hangus
        // walau proses berikutnya gagal.
        $this->tokens->markAsUsed($loginToken);

        Auth::login($user, remember: true);

        // Cegah session fixation: sesi tamu tidak boleh dipakai ulang
        // setelah identitas berubah.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Ikat ke pengundang bila pengguna datang lewat tautan referral.
        // Dilakukan di sini, bukan saat klik: sebelum login kita belum tahu
        // siapa orangnya. `attach()` menolak diri sendiri dan menolak menimpa
        // ikatan lama, jadi aman dipanggil setiap kali login.
        $kode = $request->cookie('dv_ref') ?? $request->query('ref');

        if (filled($kode)) {
            app(\App\Services\ReferralService::class)->attach($user, (string) $kode);
        }

        return redirect()
            ->intended(route('web.home'))
            ->with('status', 'Selamat datang, '.$user->display_name.'.');
    }
}
