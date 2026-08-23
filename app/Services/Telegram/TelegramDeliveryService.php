<?php

namespace App\Services\Telegram;

use App\Models\Episode;
use App\Models\User;
use App\Services\EpisodeAccessService;
use App\Services\FavoriteService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Telegram\Keyboards\EpisodeKeyboard;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu episode ke pengguna lewat Telegram.
 *
 * Ini pertemuan seluruh lapisan: Storage (lewat file_id hasil sinkronisasi),
 * Membership (lewat EpisodeAccessService), Riwayat (lewat WatchHistoryService),
 * dan Telegram (lewat TelegramServiceInterface).
 *
 * ## Yang TIDAK dikerjakan di sini
 *
 * Tidak ada satu pun aturan bisnis yang ditulis ulang. Hak menonton
 * ditanyakan ke `EpisodeAccessService` — service yang sama dengan yang
 * dipakai pemutar di website. Riwayat ditulis lewat `WatchHistoryService`,
 * favorit lewat `FavoriteService`. Itulah yang membuat perubahan di Telegram
 * langsung terlihat di website dan sebaliknya: keduanya menulis ke tempat
 * yang sama lewat pintu yang sama.
 *
 * Menyalin aturan "premium yang kedaluwarsa tidak boleh menonton" ke sini
 * akan membuat dua definisi yang harus dijaga tetap sama, dan yang satu pasti
 * akan tertinggal.
 */
class TelegramDeliveryService
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected EpisodeAccessService $access,
        protected WatchHistoryService $history,
        protected FavoriteService $favorites,
        protected TelegramCacheService $cache,
        protected TelegramRetentionService $retention
    ) {
    }

    /**
     * Kirim episode ke chat, setelah seluruh penjagaan dilewati.
     *
     * Selalu selesai dengan mengirim SESUATU ke pengguna — video, penawaran
     * premium, atau penjelasan kenapa belum bisa. Pengguna yang menekan
     * tombol lalu tidak menerima apa pun akan menekannya lagi, dan itu
     * gejala yang sama dengan bot yang mati.
     */
    public function send(
        int|string $chatId,
        ?User $user,
        Episode $episode,
        ?int $gantiPesanId = null
    ): void {
        /*
        |----------------------------------------------------------------------
        | 1. Episode harus memang bisa ditonton
        |----------------------------------------------------------------------
        */

        if (! $episode->isPublished()) {
            $this->reject($chatId, $episode, 'belum terbit',
                'Part ini belum terbit. Coba lagi setelah jadwal tayangnya.');

            return;
        }

        /*
        |----------------------------------------------------------------------
        | 2. Membership
        |----------------------------------------------------------------------
        |
        | Pertanyaannya diserahkan ke EpisodeAccessService, yang tahu bedanya
        | episode gratis, pengguna premium, dan premium yang sudah lewat masa
        | berlakunya.
        |
        */

        if (! $this->access->canWatch($user, $episode)) {

            $this->log('info', 'membership.denied', $episode, $user);

            $this->telegram->sendMessage(
                $chatId,
                $this->upgradeText($episode),
                ['reply_markup' => EpisodeKeyboard::upgrade()]
            );

            return;
        }

        /*
        |----------------------------------------------------------------------
        | 3. Video harus sudah ada di Telegram
        |----------------------------------------------------------------------
        |
        | Bot TIDAK mengunggah video saat pengguna memintanya. Sinkronisasi
        | adalah pekerjaan admin yang dijalankan sekali per berkas di antrean;
        | melakukannya di sini berarti pengguna menunggu berpuluh menit sambil
        | menatap layar diam.
        |
        */

        // Lewat cache. Ini pembacaan yang terjadi pada SETIAP penekanan
        // tombol — setiap Next, setiap Previous, setiap deep link — untuk
        // nilai yang hanya berubah saat admin menyinkronkan. Cache-nya
        // dibuang secara eksplisit oleh EpisodeVideoObserver, bukan menunggu
        // kedaluwarsa: file_id lama untuk video yang baru diganti menghasilkan
        // "video salah", gejala yang jauh lebih sulit dikenali daripada
        // "video gagal".
        $fileId = $this->cache->fileId($episode->id);

        if ($fileId === null) {

            $this->log('warning', 'delivery.missing_file_id', $episode, $user);

            $this->reject($chatId, $episode, 'belum tersinkron',
                'Video part ini belum siap dikirim lewat Telegram. '
                .'Tim kami sudah diberi tahu — coba lagi nanti, atau tonton lewat aplikasi.');

            return;
        }

        /*
        |----------------------------------------------------------------------
        | 4. Kirim, lalu catat
        |----------------------------------------------------------------------
        */

        $isFavorite = $user !== null
            && $episode->drama !== null
            && $this->favorites->isFavorite($user, $episode->drama);

        $respons = $this->telegram->sendVideo(
            $chatId,
            $fileId,
            $this->caption($episode),
            [
                'supports_streaming' => true,
                'reply_markup'       => EpisodeKeyboard::player($episode, $isFavorite),
            ]
        );

        $this->log('info', 'delivery.sent', $episode, $user);

        /*
        |----------------------------------------------------------------------
        | 5. Catat pesannya
        |----------------------------------------------------------------------
        |
        | HARUS di sini, bukan belakangan. Telegram tidak punya cara menanyakan
        | "pesan apa saja yang pernah saya kirim ke chat ini" — pasangan
        | chat_id + message_id yang tidak ditangkap pada detik ini tidak akan
        | pernah bisa dihapus.
        |
        | Pencatatannya sengaja tidak pernah melempar: video sudah sampai ke
        | pengguna, dan kegagalan menulis satu baris tabel tidak boleh berubah
        | menjadi pesan galat di layar mereka.
        |
        */
        $this->retention->catat(
            $chatId,
            $respons->messageId(),
            $user,
            $episode,
            (bool) $episode->is_vip
        );

        /*
        |----------------------------------------------------------------------
        | 6. Baru hapus video sebelumnya
        |----------------------------------------------------------------------
        |
        | Urutannya kirim-dulu-baru-hapus, dan itu bukan kebetulan. Kalau
        | dibalik, pengiriman yang gagal meninggalkan pengguna tanpa video
        | sama sekali: yang lama sudah lenyap, yang baru tidak pernah datang,
        | dan satu-satunya jalan kembali adalah mencari ulang dramanya dari
        | menu.
        |
        | Dengan urutan ini, kegagalan terburuk yang mungkin terjadi hanyalah
        | dua video menumpuk — persis keadaan sebelum fitur ini ada.
        |
        | Hanya berlaku bila permintaannya datang dari pesan video, yang
        | ditentukan CallbackHandler. Tombol di daftar part dan menu tidak
        | menghapus apa pun, karena pesan itu masih berguna untuk memilih
        | part berikutnya.
        |
        */
        $this->retention->gantikan($chatId, $gantiPesanId);

        // Riwayat ditulis SETELAH pengiriman berhasil. Menulisnya lebih dulu
        // membuat episode yang gagal terkirim tetap muncul di "lanjut
        // menonton" — di website maupun di bot.
        if ($user !== null) {
            $this->history->save($user, $episode);

            $this->log('info', 'history.updated', $episode, $user);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    private function caption(Episode $episode): string
    {
        $baris = [
            '<b>'.e($episode->drama?->title ?? 'Drama').'</b>',
            'Part '.e((string) $episode->episode_number),
        ];

        if (filled($episode->title)) {
            $baris[] = e($episode->title);
        }

        return implode("\n", $baris);
    }

    private function upgradeText(Episode $episode): string
    {
        return implode("\n", [
            '💎 <b>Part Premium</b>',
            '',
            e($episode->drama?->title ?? 'Drama').' part '.e((string) $episode->episode_number)
                .' hanya untuk anggota premium.',
            '',
            'Berlangganan untuk menonton seluruh part tanpa batas.',
        ]);
    }

    private function reject(int|string $chatId, Episode $episode, string $sebab, string $pesan): void
    {
        $this->telegram->sendMessage($chatId, $pesan);

        $this->log('info', 'delivery.rejected', $episode, null, ['sebab' => $sebab]);
    }

    private function log(string $level, string $event, Episode $episode, ?User $user, array $extra = []): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->log($level, 'telegram.'.$event, $extra + [
                'episode_id' => $episode->id,
                'drama_id'   => $episode->drama_id,
                'user_id'    => $user?->id,
            ]);
    }
}
