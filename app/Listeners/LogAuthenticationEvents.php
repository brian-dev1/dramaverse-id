<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Jejak audit untuk masuk, keluar, dan percobaan yang gagal.
 *
 * ## Kenapa tidak lewat ActivityLogger
 *
 * `ActivityLogger::log()` mengambil `Auth::id()` sendiri. Pada peristiwa
 * `Logout` pengguna sudah dilepas, dan pada `Failed` tidak pernah ada
 * pengguna yang masuk — keduanya akan tercatat sebagai `user_id` kosong,
 * yaitu justru bagian yang paling ingin diketahui.
 *
 * Jadi baris ditulis langsung, dengan id yang datang dari peristiwanya.
 *
 * ## Kenapa email percobaan gagal ikut dicatat
 *
 * Tanpa itu, "ada yang mencoba menebak kata sandi" tidak bisa dibedakan dari
 * "admin salah ketik". Yang TIDAK dicatat adalah kata sandinya — tidak
 * pernah, dalam keadaan apa pun.
 */
class LogAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        $this->tulis('masuk', $event->user->getAuthIdentifier(), [
            'guard' => $event->guard,
            'email' => $event->user->email ?? null,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->tulis('keluar', $event->user?->getAuthIdentifier(), [
            'guard' => $event->guard,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        // Kata sandinya ada di $event->credentials dan TIDAK ikut. Yang
        // diambil hanya email.
        $this->tulis('gagal masuk', $event->user?->getAuthIdentifier(), [
            'guard' => $event->guard,
            'email' => $event->credentials['email'] ?? null,
        ]);
    }

    /**
     * Terkunci karena terlalu banyak percobaan.
     *
     * Ini yang membedakan salah ketik dari serangan: `Failed` wajar terjadi,
     * `Lockout` tidak.
     */
    public function handleLockout(Lockout $event): void
    {
        $this->tulis('terkunci', null, [
            'email' => $event->request->input('email'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Penulisan
    |--------------------------------------------------------------------------
    */

    private function tulis(string $aksi, mixed $userId, array $payload): void
    {
        // Selalu ke log berkas, meski basis data bermasalah. Peristiwa
        // autentikasi adalah yang paling dibutuhkan justru saat ada yang
        // tidak beres, dan basis data yang tidak bisa ditulis tidak boleh
        // menghapus jejaknya sama sekali.
        Log::info('auth.'.str_replace(' ', '_', $aksi), $payload + [
            'user_id' => $userId,
            'ip'      => Request::ip(),
        ]);

        try {
            ActivityLog::create([
                'user_id'     => is_numeric($userId) ? (int) $userId : null,
                'action'      => $aksi,
                'module'      => 'auth',
                'description' => 'Autentikasi '.$aksi
                    .(isset($payload['email']) ? ': '.$payload['email'] : ''),
                'ip_address'  => Request::ip(),
                'user_agent'  => Str::limit((string) Request::userAgent(), 500, ''),
                'payload'     => $payload,
            ]);

        } catch (Throwable $e) {

            // Pencatatan yang gagal tidak boleh menggagalkan proses masuk.
            // Pengguna yang tidak bisa login karena tabel audit penuh adalah
            // akibat yang jauh lebih buruk daripada satu baris audit hilang.
            Log::warning('auth.audit_gagal', ['sebab' => $e->getMessage()]);
        }
    }
}
