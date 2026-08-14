<?php

namespace App\Telegram\Handlers;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserService;
use App\Support\TelegramDeepLink;
use App\Telegram\Handlers\WebsiteHandler;
use App\Telegram\Keyboards\HomeKeyboard;

/**
 * `/start`, dengan atau tanpa deep link.
 *
 * Urutannya penting: akun disinkronkan LEBIH DULU, sebelum parameter deep
 * link diproses. Tautan menonton yang dibuka orang yang belum pernah membuka
 * bot akan sampai di sini sebagai pengguna yang belum ada di basis data kita,
 * dan pemeriksaan membership tanpa pengguna selalu menjawab "tidak boleh".
 * Menyinkronkan belakangan berarti pemilik langganan premium ditolak pada
 * klik pertamanya.
 */
class StartHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserService $userService,
        protected TelegramRepositoryInterface $users
    ) {
    }

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'];

        $this->userService->syncTelegramUser($message['from']);

        $user = $this->users->findByTelegramId($message['from']['id'] ?? 0);

        $parameter = $this->parameter(trim($message['text'] ?? ''));

        /*
        |----------------------------------------------------------------------
        | Deep Link
        |----------------------------------------------------------------------
        */

        if ($parameter !== '') {

            /*
            |------------------------------------------------------------------
            | Permintaan masuk ke website
            |------------------------------------------------------------------
            |
            | Datang dari tombol "Masuk lewat Telegram" di beranda. Jawabannya
            | sama persis dengan tombol Website di menu bot — tautan sekali
            | pakai atau tombol Mini App — jadi keduanya memanggil kode yang
            | sama, bukan dua salinan yang lambat laun berbeda.
            |
            */

            if ($parameter === TelegramDeepLink::LOGIN) {

                app(WebsiteHandler::class)->kirim($chatId, $user);

                return;
            }

            if ($parameter === TelegramDeepLink::SUBSCRIBE) {

                app(PremiumHandler::class)->handle(
                    ['message' => ['chat' => $message['chat']], 'id' => null],
                    $user
                );

                return;
            }

            /*
            |------------------------------------------------------------------
            | Paket yang dipilih di website
            |------------------------------------------------------------------
            |
            | Daftar paket tidak lagi ada di dalam bot; harganya dilihat di
            | halaman VIP website. Yang menyeberang ke sini hanya id paket
            | yang ditekan, dan langkah berikutnya persis langkah yang sudah
            | ada sejak dulu: `PremiumHandler::buy()` membuat tagihan lewat
            | CheckoutService dan mengirim QRIS beserta nomornya.
            |
            | Jadi yang berpindah ke web cuma layar memilih harga. Pembayaran,
            | tagihan, bukti bayar, dan aktivasinya tidak bergeser sedikit pun.
            |
            | Diletakkan sebelum pembacaan kode affiliate: lihat
            | TelegramDeepLink::planId().
            |
            */

            if ($planId = TelegramDeepLink::planId($parameter)) {

                app(PremiumHandler::class)->buy(
                    ['message' => ['chat' => $message['chat']], 'id' => null],
                    $user,
                    $planId
                );

                return;
            }

            if ($episodeId = TelegramDeepLink::episodeId($parameter)) {
                app(WatchHandler::class)->handle($chatId, $user, $episodeId);

                return;
            }

            if ($dramaId = TelegramDeepLink::dramaId($parameter)) {
                app(EpisodeListHandler::class)->handle($chatId, $dramaId);

                return;
            }

            /*
            |------------------------------------------------------------------
            | Tautan affiliate
            |------------------------------------------------------------------
            |
            | Inilah titik pencatatan referral yang sebenarnya. Bukan klik di
            | website — klik hanya statistik dan bisa dipalsukan siapa saja
            | dengan me-refresh halaman. Di sini akun Telegram sudah pasti
            | ada, sudah pasti unik, dan sudah pasti orang yang nanti
            | bertransaksi. Ikatannya ditulis sekali seumur akun.
            |
            */

            if ($kode = TelegramDeepLink::referralCode($parameter)) {
                $this->referral($chatId, $user, $kode);

                return;
            }

            // Parameter yang tidak dikenal TIDAK dibiarkan diam. Tautan lama
            // atau salah ketik harus dijawab, lalu dilanjutkan ke menu —
            // bukan berakhir dengan layar kosong.
            $this->telegram->sendMessage(
                $chatId,
                'Tautan yang Anda buka tidak dikenali. Berikut menu utamanya.'
            );
        }

        $this->home($chatId);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Proses tautan affiliate lalu antar ke menu.
     *
     * Diam-diam gagal bila kodenya tidak sah atau pengguna sudah punya
     * pengundang: pendatang baru tidak perlu tahu urusan komisi orang lain,
     * yang ia butuhkan hanya menu.
     */
    private function referral(int|string $chatId, mixed $user, string $kode): void
    {
        $referral = app(\App\Services\ReferralService::class);

        if ($user && $referral->attach($user, $kode)) {

            $pengundang = $referral->findByCode($kode);

            // Pemberitahuan ke pengundang: satu-satunya umpan balik bahwa
            // tautannya bekerja. Kegagalan kirim (bot diblokir) tidak boleh
            // menggagalkan /start orang yang baru saja bergabung.
            if ($pengundang && $pengundang->telegram_id) {
                try {
                    $this->telegram->sendMessage(
                        $pengundang->telegram_id,
                        "<b>Referral baru</b>\n\n"
                        .'Satu akun baru bergabung lewat tautan Anda. '
                        .'Komisi masuk otomatis begitu akun itu berlangganan.'
                    );
                } catch (\Throwable) {
                    // Sengaja dibiarkan.
                }
            }
        }

        $this->home($chatId);
    }

    /** Ambil bagian setelah `/start`, kosong bila tidak ada. */
    private function parameter(string $text): string
    {
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/', $text, $cocok)) {
            return trim($cocok[1] ?? '');
        }

        return '';
    }

    private function home(int|string $chatId): void
    {
        $welcome = <<<HTML
🎭 <b>DramaVerse ID</b>

Selamat datang di <b>DramaVerse ID</b>.

Website digunakan untuk mencari drama dan memilih episode.

Telegram digunakan sebagai media untuk menonton drama.

Silakan pilih menu di bawah ini.
HTML;

        $channel = trim((string) config('telegram.channel_url'));

        if ($channel !== '') {
            $welcome .= "\n\n".'Ikuti channel resmi: <a href="'.e($channel).'">DramaVerse ID</a>';
        }

        $this->telegram->sendMessage(
            $chatId,
            $welcome,
            ['reply_markup' => HomeKeyboard::make()]
        );
    }
}
