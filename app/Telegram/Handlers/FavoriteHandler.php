<?php

namespace App\Telegram\Handlers;

use App\Models\Drama;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Favorit: daftar milik pengguna, dan tombol tambah/hapus.
 *
 * Menulis lewat `FavoriteService` — service yang sama dengan yang dipakai
 * website. Itulah yang membuat favorit yang ditambahkan dari bot langsung
 * muncul di halaman profil dan sebaliknya, tanpa ada mekanisme sinkronisasi
 * apa pun di antara keduanya. Tidak ada yang perlu disinkronkan bila
 * datanya memang cuma satu.
 */
class FavoriteHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected FavoriteService $favorites
    ) {
    }

    /** Daftar favorit, dipanggil dari menu utama. */
    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        $daftar = $this->favorites->all($user);

        if ($daftar->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "❤️ <b>Favorit</b>\n\nBelum ada drama favorit. Tekan tombol Favorit "
                .'saat menonton untuk menyimpannya di sini.'
            );

            return;
        }

        $baris = ["❤️ <b>Favorit</b>\n"];

        $tombol = [];

        foreach ($daftar as $item) {

            // FavoriteService boleh mengembalikan baris favorit atau langsung
            // dramanya, tergantung repository-nya. Keduanya diterima supaya
            // handler ini tidak ikut rusak kalau bentuknya berubah.
            //
            // Relasi `drama` juga bisa kosong bila dramanya sudah dihapus
            // tetapi baris favoritnya tertinggal. Dilewati — lebih baik
            // daripada tombol kosong yang tidak menuju ke mana-mana.
            $drama = $item instanceof Drama ? $item : ($item->drama ?? null);

            if ($drama === null) {
                continue;
            }

            $baris[] = '• '.e($drama->title);

            $tombol[] = [[
                'text'          => $drama->title,
                'callback_data' => 'el:'.$drama->id.':1',
            ]];
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $baris),
            $tombol === [] ? [] : ['reply_markup' => ['inline_keyboard' => $tombol]]
        );
    }

    /**
     * Tombol favorit pada pemutar: menambah bila belum ada, menghapus bila
     * sudah. Satu tombol untuk dua arah, karena keadaan sekarang selalu
     * terbaca di labelnya.
     */
    public function toggle(array $callback, ?User $user, int $dramaId): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        $drama = Drama::find($dramaId);

        if ($drama === null) {
            $this->telegram->sendMessage($chatId, 'Drama tidak ditemukan.');

            return;
        }

        $sudah = $this->favorites->isFavorite($user, $drama);

        if ($sudah) {
            $this->favorites->remove($user, $drama);
        } else {
            $this->favorites->add($user, $drama);
        }

        $this->log($user->id, $dramaId, $sudah ? 'removed' : 'added');

        $this->telegram->sendMessage(
            $chatId,
            $sudah
                ? '💔 <b>'.e($drama->title).'</b> dihapus dari favorit.'
                : '❤️ <b>'.e($drama->title).'</b> ditambahkan ke favorit. '
                    .'Sudah terlihat juga di aplikasi.'
        );
    }

    private function log(int $userId, int $dramaId, string $aksi): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.favorite.updated', [
                'user_id'  => $userId,
                'drama_id' => $dramaId,
                'aksi'     => $aksi,
            ]);
    }
}
