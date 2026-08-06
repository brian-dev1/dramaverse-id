<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login admin — satu-satunya tempat email + password dipakai.
 * Pengguna biasa tetap masuk lewat Telegram.
 */
class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user?->canAccessAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        /*
         * Jangan biarkan sesi admin yang sudah tidak valid tetap hidup.
         * Ini bisa terjadi bila akun dinonaktifkan/diblokir ketika sesi
         * browsernya masih aktif.
         */
        if ($user !== null && $user->isAdmin()) {
            Auth::logout();
        }

        return view('web.pages.admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        $user = Auth::user();

        if (! $user->isAdmin()) {
            $this->logoutInvalidSession($request);

            throw ValidationException::withMessages([
                'email' => 'Akun ini tidak memiliki akses admin.',
            ]);
        }

        if (! $user->is_active) {
            $this->logoutInvalidSession($request);

            throw ValidationException::withMessages([
                'email' => 'Akun admin ini sedang dinonaktifkan.',
            ]);
        }

        if ($user->is_banned) {
            $this->logoutInvalidSession($request);

            throw ValidationException::withMessages([
                'email' => 'Akun admin ini sedang diblokir.',
            ]);
        }

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * Bersihkan sesi hasil Auth::attempt() ketika akun ternyata tidak boleh
     * menggunakan panel admin.
     */
    private function logoutInvalidSession(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}