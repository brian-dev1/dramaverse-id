<?php

namespace App\Jobs;

use App\Models\ChannelPost;
use App\Models\Drama;
use App\Services\Telegram\ChannelPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kiriman otomatis ke channel saat sebuah drama dipublikasikan.
 *
 * ## Kenapa lewat antrean
 *
 * Mengirim satu drama berisi 60 episode berarti beberapa panggilan API
 * Telegram berurutan. Menjalankannya di dalam permintaan HTTP berarti admin
 * menatap tombol Simpan yang berputar selama beberapa detik, dan bila
 * Telegram sedang lambat, permintaannya kedaluwarsa — padahal dramanya sudah
 * tersimpan.
 *
 * ## Penjagaan kiriman ganda ada DI SINI, bukan hanya di observer
 *
 * Observer memeriksa sebelum job diantrekan, tapi antara pemeriksaan itu dan
 * job benar-benar berjalan bisa ada jeda beberapa detik. Menyimpan drama dua
 * kali berturut-turut — hal yang wajar dilakukan admin yang membetulkan salah
 * ketik — akan mengantrekan dua job yang keduanya lolos pemeriksaan awal.
 * Karena itu diperiksa ulang di dalam job, saat pengiriman benar-benar
 * hendak terjadi.
 */
class PostDramaToChannel implements ShouldQueue
{
    use Queueable;

    /**
     * Sekali saja, tanpa percobaan ulang.
     *
     * Percobaan ulang otomatis pada pengiriman ke channel berbahaya: kegagalan
     * yang paling mungkin adalah timeout SETELAH Telegram menerima pesannya,
     * dan mengulangnya menghasilkan postingan ganda yang dilihat semua
     * pelanggan channel. Kegagalan tercatat di tabel channel_posts, dan admin
     * bisa mengirim ulang lewat tombol manual setelah memastikan channelnya
     * memang masih kosong.
     */
    public int $tries = 1;

    public function __construct(
        public int $dramaId
    ) {
    }

    public function handle(ChannelPostService $channel): void
    {
        $drama = Drama::find($this->dramaId);

        if ($drama === null) {
            return;
        }

        if ($channel->pernahDikirim($drama)) {

            Log::info('channel.auto_post_skipped', [
                'drama_id' => $drama->id,
                'sebab'    => 'sudah pernah dikirim',
            ]);

            return;
        }

        // Diperiksa lagi: pengaturan bisa dimatikan admin setelah job
        // diantrekan tapi sebelum worker mengambilnya.
        if (! $channel->autoPostAktif()) {
            return;
        }

        $channel->kirim($drama, null, null, ChannelPost::SOURCE_AUTO);
    }
}
