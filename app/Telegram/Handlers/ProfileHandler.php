<?php

namespace App\Telegram\Handlers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\Membership\MembershipService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Support\Telegram\Notice;
use App\Support\Waktu;
use App\Telegram\Keyboards\EpisodeKeyboard;
use App\Support\Uang;

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
            $this->teks($user),
            ['reply_markup' => ['inline_keyboard' => $this->tombol($user)]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Isi
    |--------------------------------------------------------------------------
    */

    private function teks(User $user): string
    {
        $pesan = Notice::make('👤', 'Profil')
            ->rows([
                'Nama'      => $user->name,
                'Username'  => filled($user->telegram_username)
                    ? '@'.$user->telegram_username
                    : null,
                'Bergabung' => Waktu::ringkas($user->created_at, '-'),
            ]);

        /*
        |----------------------------------------------------------------------
        | Langganan
        |----------------------------------------------------------------------
        */

        $status = $this->membership->status($user);

        $aktif = $this->membership->active($user);

        $pesan->section('💎', 'Langganan');

        if ($aktif !== null) {

            $sisa = $aktif->expired_at !== null
                ? (int) ceil(now()->floatDiffInDays($aktif->expired_at, false))
                : null;

            $pesan->rows([
                'Status'         => $status['label'],
                'Paket'          => $aktif->plan?->name ?? '-',
                // Tanggal DAN jam: pertanyaan "jam berapa berhentinya" selalu
                // datang di hari terakhir, saat sudah terlambat menjawabnya.
                'Berlaku sampai' => $aktif->expired_at !== null
                    ? Waktu::lengkap($aktif->expired_at)
                    : 'tanpa batas waktu',
                'Sisa'           => $sisa === null
                    ? null
                    : max(0, $sisa).' hari',
            ]);

            // Sisa hari yang tinggal sedikit ditandai. Itu satu-satunya
            // pemberitahuan yang diterima pengguna sebelum aksesnya berhenti,
            // karena pengingat otomatis belum ada.
            if ($sisa !== null && $sisa <= 3) {
                $pesan->note('⚠️ Tinggal '.max(0, $sisa).' hari lagi — perpanjang '
                    .'sebelum part premium terkunci.');
            }

        } else {

            $pesan->rows(['Status' => $status['label']]);

            $pesan->text($status['status'] === 'expired'
                ? 'Langganan Anda sudah berakhir. Perpanjang untuk membuka '
                    .'part premium lagi.'
                : 'Anda belum berlangganan. Part premium masih terkunci.');
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

            $adaCicilan = (float) $tagihan->paid_amount > 0;

            $pesan->section('🧾', 'Tagihan menunggu pembayaran')->rows([
                'Nomor'       => $tagihan->number,
                'Paket'       => $tagihan->plan_name,
                'Total'       => Uang::invoice($tagihan),
                'Sudah masuk' => $adaCicilan
                    ? Uang::invoice($tagihan, 'paid_amount')
                        .' ('.$tagihan->paidPercent().'%)'
                    : null,
                'Kurang'      => $adaCicilan
                    ? Uang::format($tagihan->outstanding(), $tagihan->currency)
                    : null,
                'Jatuh tempo' => $tagihan->due_at !== null
                    ? Waktu::lengkapRelatif($tagihan->due_at)
                    : null,
            ]);
        }

        /*
        |----------------------------------------------------------------------
        | Aktivitas
        |----------------------------------------------------------------------
        */

        $terakhir = $this->history->latest($user, 1)->first()?->episode;

        $pesan->section('📊', 'Aktivitas')->rows([
            'Favorit'          => $this->favorites->all($user)->count().' drama',
            'Terakhir ditonton' => $terakhir === null
                ? 'belum ada'
                : ($terakhir->drama?->title ?? 'Drama')
                    .' — part '.$terakhir->episode_number,
        ]);

        return $pesan->render();
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
                'text'          => '▶️ Lanjutkan part '.$terakhir->episode_number,
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
