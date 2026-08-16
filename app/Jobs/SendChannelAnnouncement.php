<?php

namespace App\Jobs;

use App\Models\ChannelAnnouncement;
use App\Services\Telegram\ChannelAnnouncementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pengiriman satu pengumuman terjadwal.
 *
 * Diantrekan oleh `channel:announce-due`, bukan oleh tombol di panel:
 * pengumuman "kirim sekarang" dikirim langsung di dalam permintaan HTTP
 * supaya admin melihat hasilnya seketika — satu atau dua panggilan API,
 * bukan puluhan seperti katalog.
 */
class SendChannelAnnouncement implements ShouldQueue
{
    use Queueable;

    /**
     * Sekali saja, tanpa percobaan ulang.
     *
     * Alasannya sama dengan kiriman katalog: kegagalan yang paling mungkin
     * adalah timeout SETELAH Telegram menerima pesannya, dan mengulangnya
     * menghasilkan pengumuman ganda yang dibaca semua pelanggan channel.
     * Kegagalannya tercatat di barisnya sendiri, lengkap dengan sebabnya, dan
     * admin bisa mengirim ulang dari panel setelah memastikan channelnya
     * memang masih kosong.
     */
    public int $tries = 1;

    public function __construct(
        public int $pengumumanId
    ) {
    }

    public function handle(ChannelAnnouncementService $service): void
    {
        $pengumuman = ChannelAnnouncement::find($this->pengumumanId);

        if ($pengumuman === null) {
            return;
        }

        /*
        | Diperiksa lagi di sini, bukan cukup saat memungutnya.
        |
        | Antara command mengantrekan job ini dan worker menjalankannya bisa
        | ada jeda — dan justru di jeda itulah admin yang berubah pikiran
        | menekan Batalkan. Tanpa pemeriksaan kedua, pembatalannya tidak
        | berarti apa-apa: pengumumannya tetap sampai ke channel, dan yang
        | tertulis di panel adalah "Dibatalkan".
        */
        if (! $pengumuman->menunggu()) {

            Log::info('channel.announcement_skipped', [
                'pengumuman_id' => $pengumuman->id,
                'status'        => $pengumuman->status,
            ]);

            return;
        }

        $service->kirim($pengumuman);
    }
}
