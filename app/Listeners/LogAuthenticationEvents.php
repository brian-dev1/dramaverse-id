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

        $this->laporKeKeamanan((string) ($event->credentials['email'] ?? ''));
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

        Log::channel('keamanan')->error('Batas percobaan login tercapai', [
            'ip' => $event->request->ip(),
        ]);
    }

    /**
     * Salin kegagalan login ke log keamanan, dalam bentuk yang bisa dibaca
     * fail2ban.
     *
     * ## Kenapa ditulis dua kali
     *
     * `tulis()` di atas sudah mencatatnya — ke `laravel.log` dan ke tabel
     * `activity_logs`. Keduanya untuk manusia yang menyelidiki sesuatu
     * setelah kejadian. Yang ini untuk mesin yang membaca terus-menerus, dan
     * kebutuhannya berbeda cukup jauh untuk tidak bisa digabung:
     *
     * - fail2ban membaca berkas baris demi baris dengan satu regex. Bercampur
     *   dengan seluruh log aplikasi, ia harus memindai ribuan baris tak
     *   relevan setiap detik, dan regex-nya patah setiap kali ada pesan baru
     *   yang kebetulan mirip.
     * - Tabel `activity_logs` tidak bisa dibaca fail2ban sama sekali.
     * - `laravel.log` dirotasi mengikuti aturan lain dan bisa dimatikan lewat
     *   `LOG_LEVEL` di produksi. Jejak keamanan tidak boleh ikut hilang
     *   karena keputusan tentang keriuhan log aplikasi.
     *
     * ## Apa yang ditangkap di sini, yang tidak ditangkap rate limiter
     *
     * `RateLimiter::for('admin-login')` membatasi per kombinasi (email + IP).
     * Itu menutup brute force klasik: satu email, ribuan kata sandi.
     *
     * Yang lolos adalah kebalikannya — satu kata sandi umum dicoba terhadap
     * ribuan email berbeda (*password spraying*). Tiap kombinasi hanya
     * dicoba sekali, jadi tidak ada satu pun yang mendekati batas per-email,
     * sementara totalnya ribuan. Baris inilah yang membuat polanya terlihat:
     * fail2ban menghitungnya per IP tanpa peduli emailnya, lalu memblokir di
     * firewall — tempat penyerang tidak bisa lagi sekadar menunggu, karena
     * paketnya tidak sampai.
     *
     * Emailnya disamarkan. Untuk menghitung percobaan gagal per IP, alamat
     * lengkap tidak dibutuhkan; yang dibutuhkan hanya cukup petunjuk untuk
     * mengenali akun mana yang disasar. Bentuk lengkapnya tetap ada di
     * `activity_logs` bila memang perlu ditelusuri.
     */
    private function laporKeKeamanan(string $email): void
    {
        Log::channel('keamanan')->warning('Login admin gagal', [
            'ip'    => Request::ip(),
            'email' => $this->samarkanEmail($email),
            'agen'  => Str::limit((string) Request::userAgent(), 120, ''),
        ]);
    }

    /** `admin@dramaverse.id` menjadi `ad***@dramaverse.id`. */
    private function samarkanEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '(kosong)';
        }

        [$nama, $domain] = explode('@', $email, 2);

        return Str::substr($nama, 0, 2).'***@'.$domain;
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
