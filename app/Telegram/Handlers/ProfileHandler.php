<?php

namespace App\Telegram\Handlers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\Membership\MembershipService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Telegram\Keyboards\EpisodeKeyboard;

/**
 * Halaman profil pengguna di dalam bot.
 *
 * ## Kenapa isinya berubah total
 *
 * Sebelumnya handler ini mengirim satu kalimat tetap: "Fitur profil akan
 * segera tersedia." Tombolnya ada di menu sejak awal, ditekan orang, dan
 * tidak pernah menampilkan apa pun.
 *
 * Itu bukan sekadar fitur yang belum jadi. Profil adalah satu-satunya tempat
 * pengguna bisa memeriksa **apakah pembayarannya sudah masuk** — dan tanpa
 * itu, setiap pertanyaan "sudah aktif belum?" berakhir di admin.
 *
 * ## Dibaca dari service yang sama dengan website
 *
 * `MembershipService`, `WatchHistoryService`, `FavoriteService`. Tidak ada
 * satu pun query yang ditulis ulang di sini, dan tidak ada aturan membership
 * yang disalin — status yang tampil di bot selalu sama dengan yang tampil di
 * halaman profil website, karena keduanya bertanya ke tempat yang sama.
 */
class ProfileHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership,
        protected WatchHistoryService $history,
        protected FavoriteService $favorites
    ) {
    }

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage(
                $chatId,
                'Kirim /start dulu supaya akun Anda dikenali.'
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $this->baris($user)),
            ['reply_markup' => ['inline_keyboard' => $this->tombol($user)]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Isi
    |--------------------------------------------------------------------------
    */

    /** @return array<int,string> */
    private function baris(User $user): array
    {
        $baris = ['👤 <b>Profil</b>', ''];

        $baris[] = '<b>Nama</b>: '.e($user->name);

        if (filled($user->telegram_username)) {
            $baris[] = '<b>Username</b>: @'.e($user->telegram_username);
        }

        $baris[] = '<b>Bergabung</b>: '.(\App\Support\Waktu::ringkas($user->created_at, '-'));

        /*
        |----------------------------------------------------------------------
        | Langganan
        |----------------------------------------------------------------------
        */

        $baris[] = '';
        $baris[] = '💎 <b>Langganan</b>';

        $status = $this->membership->status($user);

        $aktif = $this->membership->active($user);

        $baris[] = '<b>Status</b>: '.e($status['label']);

        if ($aktif !== null) {

            $baris[] = '<b>Paket</b>: '.e($aktif->plan?->name ?? '-');

            if ($aktif->expired_at !== null) {

                $sisa = (int) ceil(now()->floatDiffInDays($aktif->expired_at, false));

                $baris[] = '<b>Berlaku sampai</b>: '.\App\Support\Waktu::lengkap($aktif->expired_at);

                // Sisa hari yang tinggal sedikit ditandai. Itu satu-satunya
                // pemberitahuan yang diterima pengguna sebelum aksesnya
                // berhenti, karena pengingat otomatis belum ada.
                $baris[] = $sisa <= 3
                    ? '⚠️ <b>Sisa</b>: '.max(0, $sisa).' hari lagi'
                    : '<b>Sisa</b>: '.$sisa.' hari';

            } else {
                $baris[] = '<b>Berlaku sampai</b>: tanpa batas waktu';
            }

        } elseif ($status['status'] === 'expired') {
            $baris[] = 'Langganan Anda sudah berakhir. Perpanjang untuk membuka '
                .'episode premium lagi.';
        } else {
            $baris[] = 'Anda belum berlangganan. Episode premium masih terkunci.';
        }

        /*
        |----------------------------------------------------------------------
        | Tagihan yang menunggu
        |----------------------------------------------------------------------
        |
        | Inilah bagian yang membuat halaman ini ada. Pengguna yang baru
        | membayar sebagian lewat Trakteer melihat sisanya di sini, bukan
        | menebak-nebak atau bertanya ke admin.
        |
        */

        $tagihan = Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::PENDING->value)
            ->latest('id')
            ->first();

        if ($tagihan !== null) {

            $baris[] = '';
            $baris[] = '🧾 <b>Tagihan menunggu pembayaran</b>';
            $baris[] = '<b>Nomor</b>: <code>'.e($tagihan->number).'</code>';
            $baris[] = '<b>Paket</b>: '.e($tagihan->plan_name);
            $baris[] = '<b>Total</b>: Rp '.number_format((float) $tagihan->total, 0, ',', '.');

            if ((float) $tagihan->paid_amount > 0) {

                $baris[] = '<b>Sudah masuk</b>: Rp '
                    .number_format((float) $tagihan->paid_amount, 0, ',', '.')
                    .' ('.$tagihan->paidPercent().'%)';

                $baris[] = '<b>Kurang</b>: Rp '
                    .number_format($tagihan->outstanding(), 0, ',', '.');
            }

            if ($tagihan->due_at !== null) {
                $baris[] = '<b>Jatuh tempo</b>: '.\App\Support\Waktu::lengkapRelatif($tagihan->due_at);
            }
        }

        /*
        |----------------------------------------------------------------------
        | Aktivitas
        |----------------------------------------------------------------------
        */

        $baris[] = '';
        $baris[] = '📊 <b>Aktivitas</b>';

        $terakhir = $this->history->latest($user, 1)->first()?->episode;

        $baris[] = '<b>Favorit</b>: '.$this->favorites->all($user)->count().' drama';

        $baris[] = $terakhir === null
            ? '<b>Terakhir ditonton</b>: belum ada'
            : '<b>Terakhir ditonton</b>: '.e($terakhir->drama?->title ?? 'Drama')
                .' — episode '.e((string) $terakhir->episode_number);

        return $baris;
    }

    /**
     * Tombol yang menyesuaikan keadaan.
     *
     * Yang punya riwayat mendapat jalan langsung melanjutkan tontonannya.
     * Menampilkan semua tombol kepada semua orang membuat yang paling
     * berguna tenggelam.
     *
     * @return array<int,array<int,array<string,string>>>
     */
    private function tombol(User $user): array
    {
        $tombol = [];

        $terakhir = $this->history->latest($user, 1)->first()?->episode;

        if ($terakhir !== null) {
            $tombol[] = [[
                'text'          => '▶️ Lanjutkan episode '.$terakhir->episode_number,
                'callback_data' => EpisodeKeyboard::WATCH.':'.$terakhir->id,
            ]];
        }

        $tombol[] = [[
            'text'          => '💎 Premium',
            'callback_data' => 'premium',
        ]];

        $tombol[] = [[
            'text'          => '🌐 Buka Website',
            'callback_data' => 'website',
        ]];

        return $tombol;
    }
}
