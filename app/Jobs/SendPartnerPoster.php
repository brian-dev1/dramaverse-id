<?php

namespace App\Jobs;

use App\Models\Drama;
use App\Models\User;
use App\Services\Telegram\PartnerPosterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Satu poster di dalam pengiriman ke grup partner.
 *
 * ## Satu job satu drama
 *
 * Bukan satu job untuk seluruh pilihan. Drama yang gagal — posternya hilang
 * dari storage, Telegram menolak gambarnya — tidak boleh menghentikan sisa
 * antrean, dan kegagalannya harus berdiri sendiri di riwayat supaya admin
 * tahu drama mana yang perlu diulang.
 *
 * Jeda antar poster diatur saat dispatch di `PartnerPosterService`, bukan
 * dengan `sleep()` yang menahan worker beserta seluruh antrean lain.
 */
class SendPartnerPoster implements ShouldQueue
{
    use Queueable;

    /**
     * Sekali saja, tanpa percobaan ulang.
     *
     * Kegagalan yang paling mungkin adalah timeout SETELAH Telegram menerima
     * gambarnya. Mengulangnya menghasilkan poster ganda di grup — dan grup
     * partner isinya orang, bukan bot, jadi kiriman kembar terlihat oleh
     * semuanya. Kegagalan tercatat di `partner_poster_sends` dan bisa dikirim
     * ulang lewat tombol satuan setelah admin memastikan grupnya memang
     * belum menerimanya.
     */
    public int $tries = 1;

    public function __construct(
        public int $dramaId,
        public ?int $pengirimId = null,
        public bool $lewatiSudahDikirim = true
    ) {
    }

    public function handle(PartnerPosterService $partner): void
    {
        $drama = Drama::find($this->dramaId);

        if ($drama === null) {
            return;
        }

        /*
        | Diperiksa ulang di sini, bukan hanya saat memilih.
        |
        | Antara admin menekan Kirim Semua dan job ini benar-benar berjalan
        | bisa ada jeda menit-an — cukup lama untuk admin kedua menekan tombol
        | yang sama, atau tombol satuan dipakai atas drama yang kebetulan juga
        | ada di antrean. Tanpa pemeriksaan kedua, yang terlihat anggota grup
        | adalah poster kembar.
        */
        if ($this->lewatiSudahDikirim && $partner->pernahDikirim($drama)) {

            Log::info('partner.poster_skipped', [
                'drama_id' => $drama->id,
                'sebab'    => 'sudah pernah dikirim',
            ]);

            return;
        }

        $partner->kirim(
            $drama,
            $this->pengirimId !== null ? User::find($this->pengirimId) : null
        );
    }
}
