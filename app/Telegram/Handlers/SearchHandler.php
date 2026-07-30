<?php

namespace App\Telegram\Handlers;

use App\Services\DramaService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserSessionService;
use App\Telegram\Keyboards\EpisodeKeyboard;

/**
 * Pencarian drama lewat percakapan.
 *
 * `$userId` di seluruh kelas ini adalah **id pengguna di basis data kita**,
 * bukan `telegram_id`. `user_sessions.user_id` adalah foreign key ke
 * `users.id`; memasukkan telegram_id ke sana melanggar constraint dan
 * muncul ke pengguna sebagai tombol yang ditekan lalu tidak terjadi apa-apa.
 * Penerjemahannya dilakukan sekali di CallbackHandler dan TelegramRouter.
 */
class SearchHandler
{
    /** Sepuluh cukup untuk satu layar tanpa harus menggulir jauh. */
    private const LIMIT = 10;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserSessionService $session,
        protected DramaService $dramas
    ) {
    }

    public function start(int $chatId, int $userId): void
    {
        $this->session->set($userId, 'SEARCH');

        $this->telegram->sendMessage(
            $chatId,
            "🔎 <b>Cari Drama</b>\n\nKetik judul drama yang ingin Anda cari."
        );
    }

    public function handle(int $chatId, int $userId, string $keyword): void
    {
        $this->session->clear($userId);

        $keyword = trim($keyword);

        if (mb_strlen($keyword) < 2) {
            $this->telegram->sendMessage(
                $chatId,
                'Kata kunci terlalu pendek. Tekan tombol Cari lagi lalu ketik minimal dua huruf.'
            );

            return;
        }

        $hasil = $this->dramas->search($keyword);

        if ($hasil->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ Tidak ada drama yang cocok dengan "'.e($keyword).'".'
            );

            return;
        }

        $baris = ['🎬 <b>Hasil Pencarian</b>', ''];

        $tombol = [];

        foreach ($hasil->take(self::LIMIT) as $drama) {

            $baris[] = '• '.e($drama->title);

            // Tombolnya membuka daftar episode, bukan langsung memutar:
            // hasil pencarian adalah drama, dan yang ditonton adalah
            // episode. Melompat ke episode pertama akan salah untuk siapa
            // pun yang sudah menonton sebagian.
            $tombol[] = [[
                'text'          => mb_substr($drama->title, 0, 60),
                'callback_data' => EpisodeKeyboard::LIST.':'.$drama->id.':1',
            ]];
        }

        if ($hasil->count() > self::LIMIT) {
            $baris[] = '';
            $baris[] = 'Menampilkan '.self::LIMIT.' dari '.$hasil->count()
                .' hasil. Persempit kata kuncinya bila yang Anda cari belum ada.';
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $baris),
            ['reply_markup' => ['inline_keyboard' => $tombol]]
        );
    }
}
