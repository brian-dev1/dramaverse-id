<?php

namespace App\Telegram\Handlers;

use App\Enums\TelegramMenuAction;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;

/**
 * Meneruskan penekanan tombol inline ke handler yang sesuai.
 *
 * Pemetaannya tidak lagi ditulis di sini: `TelegramMenuAction` yang tahu
 * handler mana milik perbuatan mana. Sebelumnya daftar ini dan daftar tombol
 * di `HomeKeyboard` ditulis terpisah, dan keduanya sempat tidak sinkron —
 * tombol Cari tidak ada di menu, dan cabang `search` tidak ada di sini, jadi
 * seluruh alur pencarian tidak bisa dijangkau siapa pun.
 */
class CallbackHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected TelegramRepositoryInterface $repository
    ) {
    }

    public function handle(array $callback): void
    {
        // Konfirmasi ke Telegram SEGERA agar tombol tidak terus berputar
        // menunggu proses handler di bawah selesai.
        $this->telegram->answerCallbackQuery($callback['id']);

        $action = TelegramMenuAction::tryFrom($callback['data'] ?? '');

        // Tombol yang tidak dikenal: tampilkan menu utama. Ini juga yang
        // terjadi pada tombol lama yang masih menempel di pesan yang sudah
        // terkirim setelah menunya diubah dari panel.
        if ($action === null || $action->isLink()) {
            $this->home($callback);

            return;
        }

        if ($action->startsConversation()) {
            $this->startSearch($callback);

            return;
        }

        $handler = $action->handler();

        if ($handler === null) {
            $this->home($callback);

            return;
        }

        app($handler)->handle($callback);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Mulai percakapan pencarian.
     *
     * SearchHandler menerima chat dan pengguna terpisah: balasannya dikirim
     * ke CHAT, sedangkan state percakapan disimpan per PENGGUNA.
     *
     * Yang disimpan adalah **id pengguna di basis data kita**, bukan
     * `telegram_id`. Tabel `user_sessions.user_id` adalah foreign key ke
     * `users.id`; memasukkan telegram_id ke sana melanggar constraint dan
     * melempar QueryException — yang muncul ke pengguna sebagai tombol yang
     * ditekan lalu tidak terjadi apa-apa sama sekali.
     */
    private function startSearch(array $callback): void
    {
        $user = $this->repository->findByTelegramId($callback['from']['id'] ?? 0);

        if ($user === null) {

            // Belum tersinkron: bisa terjadi kalau pengguna menekan tombol
            // pada pesan lama sementara akunnya sudah dihapus. Menu utama
            // memanggil StartHandler, yang menyinkronkan akunnya lagi.
            $this->home($callback);

            return;
        }

        app(SearchHandler::class)->start(
            (int) $callback['message']['chat']['id'],
            (int) $user->id,
        );
    }

    private function home(array $callback): void
    {
        app(StartHandler::class)->handle([
            'chat' => $callback['message']['chat'],
            'from' => $callback['from'],
            'text' => '/start',
        ]);
    }
}
