<?php

namespace App\Telegram\Handlers;

use App\Models\Drama;
use App\Models\Episode;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Telegram\Keyboards\EpisodeKeyboard;

/**
 * Daftar episode satu drama, berhalaman.
 *
 * Halaman diperlukan, bukan hiasan: Telegram membatasi jumlah tombol pada
 * satu keyboard, dan drama dengan 100 episode akan membuat seluruh pesannya
 * ditolak — bukan cuma terpotong.
 */
class EpisodeListHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    public function handle(int|string $chatId, int $dramaId, int $halaman = 1): void
    {
        $drama = Drama::find($dramaId);

        if ($drama === null) {
            $this->telegram->sendMessage($chatId, 'Drama tidak ditemukan.');

            return;
        }

        $perHalaman = (int) config('telegram.episode_page_size', 20);

        $total = Episode::query()
            ->where('drama_id', $dramaId)
            ->published()
            ->count();

        if ($total === 0) {
            $this->telegram->sendMessage(
                $chatId,
                '<b>'.e($drama->title)."</b>\n\nBelum ada episode yang terbit."
            );

            return;
        }

        $totalHalaman = (int) ceil($total / $perHalaman);

        // Halaman datang dari callback_data, jadi nilainya bisa apa saja —
        // termasuk 0 atau 999 dari tombol lama yang menempel di pesan lama.
        $halaman = max(1, min($halaman, $totalHalaman));

        $episodes = Episode::query()
            ->where('drama_id', $dramaId)
            ->published()
            ->orderBy('episode_number')
            ->forPage($halaman, $perHalaman)
            ->get();

        $this->telegram->sendMessage(
            $chatId,
            sprintf(
                "📑 <b>%s</b>\n\n%d episode — halaman %d dari %d.\nPilih episode di bawah.",
                e($drama->title),
                $total,
                $halaman,
                $totalHalaman
            ),
            [
                'reply_markup' => EpisodeKeyboard::episodeList(
                    $dramaId,
                    $episodes,
                    $halaman,
                    $totalHalaman
                ),
            ]
        );
    }
}
