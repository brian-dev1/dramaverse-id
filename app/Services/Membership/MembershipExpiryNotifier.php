<?php

namespace App\Services\Membership;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\TelegramRetentionService;
use App\Support\Telegram\Notice;
use App\Support\Waktu;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memberi tahu pengguna bahwa masa VIP-nya sudah berakhir, lalu membereskan
 * sisa-sisanya.
 *
 * ## Urutannya disengaja
 *
 * Pesan dikirim LEBIH DULU, penarikan video menyusul. Pengguna yang tiba-tiba
 * kehilangan video dari chat-nya tanpa penjelasan akan menyimpulkan botnya
 * rusak; pengguna yang sudah membaca "paket berakhir, video premium ditarik"
 * mengerti apa yang terjadi. Urutan sebaliknya menghasilkan tiket dukungan.
 *
 * ## Sekali saja
 *
 * `subscriptions.expiry_notified_at` menjadi penandanya. Scheduler berjalan
 * tiap sepuluh menit; tanpa penanda itu pengguna yang langganannya baru habis
 * akan menerima pesan yang sama enam kali per jam, dan bot yang nge-spam
 * lebih sering diblokir daripada diperpanjang.
 */
class MembershipExpiryNotifier
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected TelegramRetentionService $retention
    ) {
    }

    /**
     * Proses satu langganan yang baru saja kedaluwarsa.
     *
     * @return bool true bila pemberitahuannya benar-benar terkirim
     */
    public function tangani(Subscription $subscription): bool
    {
        // Sudah pernah diberi tahu — tidak ada yang perlu dikerjakan.
        if ($subscription->expiry_notified_at !== null) {
            return false;
        }

        $user = $subscription->user ?? User::find($subscription->user_id);

        if ($user === null) {
            $subscription->forceFill(['expiry_notified_at' => now()])->save();

            return false;
        }

        $terkirim = $this->kirimPesan($user, $subscription);

        /*
        |----------------------------------------------------------------------
        | Tarik video premium yang masih bisa ditarik
        |----------------------------------------------------------------------
        |
        | Ditandai selesai APA PUN hasilnya. Video yang usianya lewat 48 jam
        | memang tidak bisa dihapus — mengulanginya tiap sepuluh menit hanya
        | membakar kuota panggilan bot untuk kegagalan yang sudah pasti.
        | Baris yang tidak terhapus tetap terlihat di panel admin dengan
        | status "Lewat 48 jam".
        |
        */
        $hasil = $this->retention->tarikMilikPengguna($user->id);

        $subscription->forceFill(['expiry_notified_at' => now()])->save();

        Log::info('membership.expiry_notified', [
            'user_id'         => $user->id,
            'subscription_id' => $subscription->id,
            'pesan_terkirim'  => $terkirim,
            'video'           => $hasil,
        ]);

        return $terkirim;
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    private function kirimPesan(User $user, Subscription $subscription): bool
    {
        // Pengguna yang mendaftar lewat website saja tidak punya chat
        // Telegram. Bukan kegagalan — hanya tidak ada tujuan kirim.
        if (blank($user->telegram_id)) {
            return false;
        }

        try {
            $this->telegram->sendMessage(
                $user->telegram_id,
                $this->teks($user, $subscription),
                [
                    'parse_mode'   => 'HTML',
                    // `up` adalah callback_data yang sudah dipakai tombol
                    // upgrade di EpisodeKeyboard. Memakai konstantanya, bukan
                    // menulis ulang hurufnya, supaya tombol ini ikut berubah
                    // kalau kodenya suatu saat diganti.
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [[
                                'text'          => '🔄 Perpanjang VIP',
                                'callback_data' => \App\Telegram\Keyboards\EpisodeKeyboard::UPGRADE,
                            ]],
                        ],
                    ],
                ]
            );

            return true;

        } catch (Throwable $e) {

            // Bot diblokir, chat dihapus, akun dinonaktifkan. Dicatat, tidak
            // dilempar: satu pengguna yang tak terjangkau tidak boleh
            // menghentikan pemrosesan pengguna berikutnya dalam batch.
            Log::warning('membership.expiry_notice_failed', [
                'user_id' => $user->id,
                'pesan'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function teks(User $user, Subscription $subscription): string
    {
        $nama = $subscription->plan?->name ?? 'VIP';

        return Notice::make('⏰', 'Masa VIP Anda sudah berakhir')
            ->lead('Akun Anda otomatis kembali ke paket Gratis.')
            ->rows([
                'Paket' => (string) $nama,
                // Tanggal DAN jam. "Berakhir 7 Agustus" menimbulkan pertanyaan
                // "jam berapa" tepat pada saat pengguna paling ingin tahu
                // jawabannya.
                'Mulai'    => $subscription->started_at !== null
                    ? Waktu::lengkap($subscription->started_at)
                    : null,
                'Berakhir' => Waktu::lengkap($subscription->expired_at),
            ])
            ->text('Mulai sekarang:')
            ->bullets([
                'Part VIP terkunci',
                'Video premium yang sudah dikirim ditarik dari chat ini',
                'Part gratis tetap bisa ditonton seperti biasa',
            ])
            ->note('Perpanjang kapan saja — sisa hari dari pembelian baru '
                .'ditambahkan, bukan menghapus riwayat Anda.')
            ->render();
    }
}
