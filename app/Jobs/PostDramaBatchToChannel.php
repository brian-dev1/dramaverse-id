<?php

namespace App\Jobs;

use App\Models\ChannelPost;
use App\Models\Drama;
use App\Models\User;
use App\Services\Telegram\ChannelPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Satu drama di dalam pengiriman massal ke channel.
 *
 * ## Kenapa job sendiri, bukan memakai PostDramaToChannel
 *
 * `PostDramaToChannel` adalah kiriman OTOMATIS saat drama dipublikasikan:
 * ia berhenti bila pengaturan auto-post dimatikan, dan selalu memakai
 * `SOURCE_AUTO`. Kiriman massal adalah tindakan manual admin — ia harus tetap
 * berjalan meski auto-post mati, harus tercatat sebagai `SOURCE_MANUAL`
 * lengkap dengan siapa yang menekannya, dan boleh membawa rentang part.
 *
 * Menambahkan parameter ke job otomatis untuk melayani dua maksud itu berarti
 * satu job dengan dua mode yang saling menonaktifkan penjagaan masing-masing.
 * Dua job yang sama-sama tipis, memanggil `ChannelPostService::kirim()` yang
 * sama, lebih mudah dibaca daripada satu job bercabang.
 *
 * ## Satu job satu drama
 *
 * Bukan satu job untuk seluruh pilihan. Drama yang gagal — poster hilang,
 * Telegram menolak captionnya — tidak boleh menghentikan sisa antrean, dan
 * kegagalannya harus berdiri sendiri di riwayat supaya admin tahu drama mana
 * yang perlu diulang. Jeda antar drama diatur saat dispatch di
 * `ChannelBulkService`, bukan dengan `sleep()` yang menahan worker.
 */
class PostDramaBatchToChannel implements ShouldQueue
{
    use Queueable;

    /**
     * Sekali saja, tanpa percobaan ulang.
     *
     * Alasannya sama dengan `PostDramaToChannel`: kegagalan yang paling
     * mungkin adalah timeout SETELAH Telegram menerima pesannya, dan
     * mengulangnya menghasilkan postingan ganda yang dilihat semua pelanggan
     * channel. Kegagalan tercatat di `channel_posts` dan bisa dikirim ulang
     * lewat tombol manual setelah admin memastikan channelnya memang kosong.
     */
    public int $tries = 1;

    public function __construct(
        public int $dramaId,
        public ?int $dari = null,
        public ?int $sampai = null,
        public ?int $pengirimId = null,
        public bool $lewatiSudahDikirim = true
    ) {
    }

    public function handle(ChannelPostService $channel): void
    {
        $drama = Drama::with(['country:id,name', 'genres:id,name'])->find($this->dramaId);

        if ($drama === null) {
            return;
        }

        /*
        | Diperiksa ulang di sini, bukan hanya saat memilih.
        |
        | Antara admin menekan Kirim dan job ini benar-benar berjalan bisa ada
        | jeda menit-an — cukup lama untuk kiriman otomatis dari observer, atau
        | admin kedua yang menekan tombol satuan, menaruh drama yang sama di
        | channel lebih dulu. Tanpa pemeriksaan kedua, yang terlihat pelanggan
        | channel adalah postingan kembar.
        */
        if ($this->lewatiSudahDikirim && $channel->pernahDikirim($drama)) {

            Log::info('channel.bulk_post_skipped', [
                'drama_id' => $drama->id,
                'sebab'    => 'sudah pernah dikirim',
            ]);

            return;
        }

        // Episode kosong pada rentang berarti postingan berisi poster dan
        // judul saja — tidak ada yang bisa ditekan pembacanya. Ditahan di
        // sini seperti tombol satuan menahannya di controller.
        if ($channel->episodes($drama, $this->dari, $this->sampai)->isEmpty()) {

            Log::info('channel.bulk_post_skipped', [
                'drama_id' => $drama->id,
                'sebab'    => 'tidak ada part pada rentang itu',
            ]);

            return;
        }

        $channel->kirim(
            $drama,
            $this->dari,
            $this->sampai,
            ChannelPost::SOURCE_MANUAL,
            $this->pengirimId !== null ? User::find($this->pengirimId) : null
        );
    }
}
