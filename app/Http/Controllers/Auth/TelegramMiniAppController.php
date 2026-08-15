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
 *
 * ## Sesi yang menempel pada akun sebelumnya
 *
 * Telegram memakai satu webview untuk SEMUA akun yang masuk di perangkat
 * itu, dan cookie sesi kita ikut dipakai bersama. Orang yang berganti akun
 * di aplikasi Telegram — dari akun A ke akun B — membuka Mini App dengan
 * initData milik B, tetapi membawa cookie sesi milik A.
 *
 * Versi lama berhenti di `if (Auth::check())` dan menjawab "sudah login"
 * tanpa pernah melihat initData-nya. Akibatnya B melihat riwayat tontonan,
 * status VIP, dan saldo referral milik A — bukan sekadar salah nama di
 * pojok layar. Karena itu identitas di dalam initData SELALU dibandingkan
 * dengan identitas sesi, dan yang menang adalah initData: ia ditandatangani
 * Telegram beberapa detik lalu, sementara cookie bisa berumur berbulan-bulan.
 */
class TelegramMiniAppController extends Controller
{
    public function __construct(
        protected UserService $users
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
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

            /*
            | Sesi yang sudah ada TIDAK diputus di sini.
            |
            | initData yang tidak sah paling sering berarti halaman dibuka di
            | luar Mini App, atau initData-nya kedaluwarsa karena webview
            | dibiarkan terbuka semalaman — bukan berarti orangnya bukan
            | pemilik sesi. Melempar keluar setiap kali data itu basi berarti
            | pengguna yang benar kehilangan sesinya tanpa sebab.
            */
            return response()->json([
                'ok'      => false,
                'message' => 'Data Telegram tidak valid atau sudah kedaluwarsa.',
            ], 403);
        }

        $telegramId = (int) ($payload['user']['id'] ?? 0);

        $sesi = Auth::user();

        // Sesi yang memang milik orang yang sedang membuka Mini App. Tidak
        // ada yang perlu dikerjakan — dan yang lebih penting, sesinya tidak
        // diputar ulang. Regenerasi id sesi pada SETIAP pembukaan halaman
        // berarti tab lain yang sedang terbuka ikut kehilangan sesinya.
        if ($sesi !== null && (int) $sesi->telegram_id === $telegramId) {
            return response()->json([
                'ok'          => true,
                'already'     => true,
                'name'        => $sesi->display_name,
                'start_param' => $payload['start_param'],
            ]);
        }

        $berganti = $sesi !== null;

        if ($berganti) {
            /*
            | Sesi akun lama dibuang SEBELUM akun baru diperiksa.
            |
            | Kalau akun B ternyata diblokir, jawabannya keluar lewat cabang
            | di bawah — dan pada saat itu sesi A harus sudah hilang. Kalau
            | urutannya dibalik, orang yang aksinya diblokir tetap duduk di
            | dalam sesi milik akun lain.
            |
            | `logout()` penuh, bukan `logoutCurrentDevice()`. Yang kedua
            | hanya mengosongkan sesi dan MENINGGALKAN cookie "ingat saya"
            | milik A di perangkat itu. Pada cabang akun diblokir di bawah —
            | yang keluar tanpa login siapa pun — cookie itu akan
            | memulihkan sesi A pada permintaan berikutnya, dan lubangnya
            | kembali persis seperti semula. `logout()` ikut membuang
            | cookie-nya.
            |
            | Harganya: `remember_token` milik A diputar ulang, jadi A perlu
            | masuk lagi di perangkat lain yang mengandalkan "ingat saya".
            | Itu dibayar sadar — sesi yang bocor ke akun lain jauh lebih
            | mahal daripada satu kali login ulang.
            */
            Auth::logout();

            $request->session()->invalidate();

            $request->session()->regenerateToken();
        }

        $user = $this->users->syncTelegramUser($payload['user']);

        if ($user->is_banned || ! $user->is_active) {
            return response()->json([
                'ok'      => false,
                'reset'   => $berganti,
                'message' => 'Akun Anda tidak aktif. Hubungi admin.',
            ], 403);
        }

        if ($berganti) {
            Log::info('telegram.miniapp.ganti_akun', [
                'dari' => $sesi->id,
                'ke'   => $user->id,
            ]);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Ikatan referral, sama seperti pada login lewat tautan bot. Aman
        // dipanggil berulang: attach() menolak menimpa upline yang sudah ada.
        $kode = $request->cookie('dv_ref') ?? $request->input('start_param');

        if (filled($kode)) {
            app(\App\Services\ReferralService::class)->attach($user, (string) $kode);
        }

        return response()->json([
            'ok'          => true,

            // Dipakai halaman untuk memutuskan perlu-tidaknya memuat ulang:
            // sesi yang baru berganti pemilik menampilkan nama, riwayat, dan
            // status VIP milik akun lama sampai halamannya diambil ulang.
            'switched'    => $berganti,

            'name'        => $user->display_name,
            'start_param' => $payload['start_param'],
        ]);
    }
}
