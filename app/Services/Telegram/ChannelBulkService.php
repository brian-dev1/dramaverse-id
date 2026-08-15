<?php

namespace App\Services\Telegram;

use App\Jobs\PostDramaBatchToChannel;
use App\Models\ChannelPost;
use App\Models\Drama;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Kirim beberapa drama ke channel dalam satu klik.
 *
 * ## Kenapa terpisah dari ChannelPostService
 *
 * `ChannelPostService` tahu cara menyusun dan mengirim SATU postingan.
 * Kelas ini tidak tahu apa-apa soal caption, batas 1024 karakter, atau
 * poster — ia hanya memutuskan drama mana yang layak diantrekan dan dengan
 * jeda berapa. Aturan pengirimannya tetap satu, di tempat yang sama, dipakai
 * tombol satuan maupun tombol massal. Bentuk ini mengikuti
 * `TelegramBulkService`, yang berdiri di samping `TelegramVideoSyncService`
 * dengan alasan yang persis sama.
 *
 * ## Semuanya lewat antrean
 *
 * Satu drama 60 episode saja sudah beberapa panggilan API Telegram
 * berurutan. Sepuluh drama di dalam satu request HTTP berarti admin menatap
 * tombol yang berputar sampai permintaannya kedaluwarsa — dan pada saat itu
 * sebagian drama sudah terkirim, sebagian belum, tanpa ada yang tahu batasnya
 * di mana. Yang dikembalikan tombolnya karena itu bukan "berhasil", melainkan
 * "sekian diantrekan"; hasil tiap drama muncul satu per satu di riwayat.
 *
 * ## Jeda antar drama
 *
 * Telegram membatasi sekitar 20 pesan per menit ke satu channel, dan satu
 * drama bisa jadi 3-4 pesan. Mengantrekan 20 drama tanpa jeda berarti worker
 * menembakkan ratusan pesan dalam hitungan detik, Telegram menjawab 429, dan
 * yang tercatat di riwayat adalah deretan "Gagal" untuk drama yang sebenarnya
 * tidak bermasalah. Jeda diberikan lewat `delay()` saat dispatch — bukan
 * `sleep()` di dalam job, yang menahan worker beserta seluruh antrean lain
 * (unggahan, broadcast) selama jeda itu.
 */
class ChannelBulkService
{
    /**
     * Batas jumlah drama sekali klik.
     *
     * Bukan soal Telegram, tapi soal antrean: 50 drama pada jeda di bawah
     * sudah berarti belasan menit worker sibuk mengirim ke channel. Angka
     * yang lebih besar dari itu lebih baik dipecah jadi dua kali klik, supaya
     * admin masih bisa membatalkan lewat mengosongkan antrean bila salah
     * pilih.
     */
    public const LIMIT = 50;

    /**
     * Jeda antar drama, dalam detik.
     *
     * Diperhitungkan untuk drama tebal: 4 pesan per drama pada jeda 20 detik
     * setara 12 pesan per menit, masih di bawah batas ~20 milik Telegram.
     */
    public const JEDA_DETIK = 20;

    public function __construct(
        protected ChannelPostService $channel
    ) {
    }

    /**
     * Antrekan pengiriman untuk id drama yang dipilih.
     *
     * Bentuk kembaliannya sama dengan `TelegramBulkService` supaya panel admin
     * bisa melaporkannya dengan kalimat yang sama.
     *
     * @param  array<int>  $ids
     * @return array{queued:int, skipped:array<string>, perkiraan:int}
     */
    public function kirim(
        array $ids,
        ?User $pengirim = null,
        bool $lewatiSudahDikirim = true
    ): array {

        $dilewati = [];

        $diantre = 0;

        /*
        | Penghalang diperiksa sekali di depan, bukan per drama.
        |
        | Token bot kosong atau chat id belum diatur berlaku untuk seluruh
        | pilihan. Membiarkan jobnya diantrekan berarti 50 baris "Gagal" di
        | riwayat dengan alasan yang sama persis, dan admin harus
        | membersihkannya sendiri.
        */
        if ($alasan = $this->channel->penghalang()) {
            return ['queued' => 0, 'skipped' => [$alasan], 'perkiraan' => 0];
        }

        foreach ($this->ambil($ids) as $drama) {

            if ($lewatiSudahDikirim && $this->channel->pernahDikirim($drama)) {
                $dilewati[] = "{$drama->title}: sudah pernah dikirim.";

                continue;
            }

            // Drama tanpa episode siap tidak ditolak di sini — barisnya tetap
            // ditulis tanpa tautan, sama seperti tombol satuan. Yang ditolak
            // hanya drama yang benar-benar tidak punya episode sama sekali,
            // karena postingannya jadi poster dan judul saja.
            if ($drama->episodes()->count() === 0) {
                $dilewati[] = "{$drama->title}: belum punya part.";

                continue;
            }

            PostDramaBatchToChannel::dispatch(
                $drama->id,
                null,
                null,
                $pengirim?->id,
                $lewatiSudahDikirim
            )->delay(now()->addSeconds($diantre * self::JEDA_DETIK));

            $diantre++;
        }

        Log::info('channel.bulk_post', [
            'diantre'  => $diantre,
            'dilewati' => count($dilewati),
            'oleh'     => $pengirim?->id,
        ]);

        return [
            'queued'    => $diantre,
            'skipped'   => $dilewati,

            // Perkiraan menit sampai yang terakhir terkirim. Ditampilkan
            // supaya admin tidak menyangka antreannya macet ketika channel
            // masih sepi satu menit setelah tombolnya ditekan.
            'perkiraan' => (int) ceil(max(0, $diantre - 1) * self::JEDA_DETIK / 60),
        ];
    }

    /**
     * Drama terpilih, dibatasi LIMIT, dengan relasi yang dipakai template.
     *
     * `country` dan `genres` dimuat di sini supaya pemeriksaan di atas tidak
     * memicu query per drama. Yang dipakai job nanti adalah query-nya sendiri
     * — model ini tidak diserahkan ke antrean.
     *
     * @param  array<int>  $ids
     * @return \Illuminate\Support\Collection<int,Drama>
     */
    private function ambil(array $ids): \Illuminate\Support\Collection
    {
        $bersih = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(self::LIMIT)
            ->all();

        return Drama::query()
            ->whereIn('id', $bersih)
            ->orderBy('title')
            ->get();
    }

    /**
     * Id drama yang pernah berhasil dikirim ke channel.
     *
     * Dipakai panel admin untuk menandai barisnya dan mengeluarkannya dari
     * "centang semua" — satu query untuk seluruh tabel, bukan
     * `pernahDikirim()` per baris.
     *
     * @return array<int,bool>
     */
    public function sudahDikirim(): array
    {
        return ChannelPost::query()
            ->where('status', ChannelPost::STATUS_SENT)
            ->distinct()
            ->pluck('drama_id')
            ->filter()
            ->flip()
            ->map(fn () => true)
            ->all();
    }
}
