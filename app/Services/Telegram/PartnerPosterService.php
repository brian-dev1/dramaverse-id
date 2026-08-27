<?php

namespace App\Services\Telegram;

use App\Jobs\SendPartnerPoster;
use App\Models\Drama;
use App\Models\PartnerPosterSend;
use App\Models\User;
use App\Services\Admin\SettingService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use Throwable;

/**
 * Kirim poster drama beserta judulnya ke grup partner.
 *
 * ## Ini bukan "Kirim ke Channel"
 *
 * Dua fitur yang terlihat mirip tetapi melayani orang yang berbeda:
 *
 * - **Channel** — untuk pelanggan. Isinya poster, sinopsis, dan daftar
 *   episode berikut tautan bot supaya bisa langsung ditonton.
 * - **Grup partner** — untuk segelintir orang yang akan mempostingnya ulang
 *   di media sosial mereka. Yang mereka butuhkan cuma gambar dan judulnya.
 *   Tautan bot justru mengganggu, karena akan ikut tersalin ke TikTok.
 *
 * Karena itu tidak ada satu baris pun di sini yang memanggil
 * `ChannelPostService`. Menyatukan keduanya berarti perubahan pada caption
 * channel diam-diam mengubah apa yang diterima partner, dan sebaliknya.
 *
 * ## Semuanya lewat antrean
 *
 * Satu klik bisa berarti puluhan poster, dan tiap poster adalah satu unggahan
 * gambar ke Telegram. Melakukannya di dalam satu request HTTP berarti admin
 * menatap tombol berputar sampai permintaannya kedaluwarsa — dengan sebagian
 * poster sudah terkirim dan sebagian belum, tanpa ada yang tahu batasnya di
 * mana.
 *
 * Jeda antar poster diberikan lewat `delay()` saat dispatch, bukan `sleep()`
 * di dalam job. `sleep()` menahan worker beserta seluruh antrean lain
 * (unggahan video, broadcast) selama jeda itu berlangsung.
 */
class PartnerPosterService
{
    /**
     * Batas jumlah poster sekali klik.
     *
     * Bukan batas Telegram, melainkan batas kesabaran: 60 poster pada jeda di
     * bawah sudah berarti lima menit antrean. Lebih dari itu sebaiknya dipecah
     * jadi dua kali klik, supaya masih ada kesempatan membatalkan lewat
     * mengosongkan antrean bila ternyata salah.
     */
    public const LIMIT = 60;

    /**
     * Jeda antar poster, dalam detik.
     *
     * Telegram membatasi sekitar 20 pesan per menit ke satu grup. Satu poster
     * adalah satu pesan, jadi jeda 5 detik setara 12 pesan per menit — masih
     * di bawah batas, dengan ruang untuk lalu lintas bot yang lain pada saat
     * bersamaan.
     */
    public const JEDA_DETIK = 5;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected SettingService $settings
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan sebelum kirim
    |--------------------------------------------------------------------------
    */

    /** Chat id grup partner, atau null bila belum diatur. */
    public function chatId(): ?string
    {
        $nilai = trim((string) $this->settings->get('partner_chat_id', ''));

        return $nilai !== '' ? $nilai : null;
    }

    /**
     * Id topik tujuan, atau null bila kiriman masuk ke topik General.
     *
     * Grup bertopik tetap menerima kiriman tanpa `message_thread_id` — yang
     * terjadi hanya kiriman itu jatuh ke topik General. Karena itu ini
     * opsional dan tidak pernah jadi penghalang.
     */
    public function threadId(): ?int
    {
        $nilai = trim((string) $this->settings->get('partner_thread_id', ''));

        return ctype_digit($nilai) && (int) $nilai > 0 ? (int) $nilai : null;
    }

    /**
     * Alasan kenapa pengiriman belum bisa dilakukan, atau null bila siap.
     *
     * Dipakai panel untuk mematikan tombol beserta alasannya, bukan
     * membiarkan admin menekannya lalu menerima deretan kegagalan yang
     * sebabnya sama semua.
     */
    public function penghalang(): ?string
    {
        if (! $this->telegram->isConfigured()) {
            return 'Token bot belum diisi di .env (TELEGRAM_BOT_TOKEN).';
        }

        if ($this->chatId() === null) {
            return 'Chat ID grup partner belum diisi di Pengaturan → Grup Partner.';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Memilih
    |--------------------------------------------------------------------------
    */

    /**
     * Id drama yang sudah pernah BERHASIL dikirim ke grup yang berlaku
     * sekarang.
     *
     * Satu query untuk seluruh tabel, bukan pemeriksaan per baris — daftar
     * dramanya bisa ratusan, dan satu query per baris adalah halaman admin
     * yang melambat diam-diam seiring katalognya bertambah.
     *
     * @return array<int,bool>
     */
    public function sudahDikirim(): array
    {
        $chatId = $this->chatId();

        if ($chatId === null) {
            return [];
        }

        return PartnerPosterSend::query()
            ->where('chat_id', $chatId)
            ->where('status', PartnerPosterSend::STATUS_SENT)
            ->distinct()
            ->pluck('drama_id')
            ->filter()
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    public function pernahDikirim(Drama $drama): bool
    {
        $chatId = $this->chatId();

        if ($chatId === null) {
            return false;
        }

        return PartnerPosterSend::query()
            ->where('drama_id', $drama->id)
            ->where('chat_id', $chatId)
            ->where('status', PartnerPosterSend::STATUS_SENT)
            ->exists();
    }

    /**
     * Seluruh drama yang punya poster, urut judul.
     *
     * Yang tidak punya poster sengaja tidak ikut: fitur ini mengirim gambar,
     * dan drama tanpa gambar tidak punya apa pun untuk dikirim. Menampilkannya
     * di daftar hanya menimbulkan pertanyaan kenapa ia selalu dilewati.
     *
     * @return Collection<int,Drama>
     */
    public function kandidat(): Collection
    {
        return Drama::query()
            ->select(['id', 'title', 'poster', 'total_episode'])
            ->whereNotNull('poster')
            ->where('poster', '!=', '')
            ->orderBy('title')
            ->get();
    }

    /**
     * Drama yang belum pernah berhasil dikirim ke grup ini.
     *
     * @return Collection<int,Drama>
     */
    public function belumDikirim(): Collection
    {
        $sudah = $this->sudahDikirim();

        return $this->kandidat()->reject(
            fn (Drama $drama) => isset($sudah[$drama->id])
        )->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Mengantrekan
    |--------------------------------------------------------------------------
    */

    /**
     * Antrekan seluruh drama yang belum dikirim.
     *
     * Yang dikembalikan bukan "berhasil", melainkan "sekian diantrekan".
     * Hasil tiap poster muncul satu per satu di riwayat, karena pada saat
     * fungsi ini selesai belum satu pun benar-benar terkirim.
     *
     * @return array{queued:int, dilewati:array<string>, perkiraan:int}
     */
    public function antrekanSemua(?User $pengirim = null): array
    {
        return $this->antrekan($this->belumDikirim()->pluck('id')->all(), $pengirim);
    }

    /**
     * @param  array<int>  $ids
     * @return array{queued:int, dilewati:array<string>, perkiraan:int}
     */
    public function antrekan(array $ids, ?User $pengirim = null, bool $lewatiSudahDikirim = true): array
    {
        /*
        | Penghalang diperiksa sekali di depan, bukan per drama.
        |
        | Token bot kosong atau chat id belum diatur berlaku untuk seluruh
        | pilihan. Membiarkan jobnya diantrekan berarti puluhan baris "Gagal"
        | di riwayat dengan alasan yang sama persis, dan admin harus
        | membersihkannya sendiri.
        */
        if ($alasan = $this->penghalang()) {
            return ['queued' => 0, 'dilewati' => [$alasan], 'perkiraan' => 0];
        }

        $bersih = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(self::LIMIT)
            ->all();

        $dramas = Drama::query()
            ->select(['id', 'title', 'poster'])
            ->whereIn('id', $bersih)
            ->orderBy('title')
            ->get();

        $dilewati = [];
        $diantre = 0;

        foreach ($dramas as $drama) {

            if (blank($drama->poster)) {
                $dilewati[] = "{$drama->title}: belum punya poster.";

                continue;
            }

            if ($lewatiSudahDikirim && $this->pernahDikirim($drama)) {
                $dilewati[] = "{$drama->title}: sudah pernah dikirim.";

                continue;
            }

            SendPartnerPoster::dispatch($drama->id, $pengirim?->id, $lewatiSudahDikirim)
                ->delay(now()->addSeconds($diantre * self::JEDA_DETIK));

            $diantre++;
        }

        Log::info('partner.poster_bulk', [
            'diantre'  => $diantre,
            'dilewati' => count($dilewati),
            'oleh'     => $pengirim?->id,
        ]);

        return [
            'queued'   => $diantre,
            'dilewati' => $dilewati,

            // Perkiraan menit sampai yang terakhir terkirim. Ditampilkan
            // supaya admin tidak menyangka antreannya macet ketika grup masih
            // sepi satu menit setelah tombolnya ditekan.
            'perkiraan' => (int) ceil(max(0, $diantre - 1) * self::JEDA_DETIK / 60),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Mengirim
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim poster satu drama. Selalu menghasilkan baris riwayat.
     */
    public function kirim(Drama $drama, ?User $pengirim = null): PartnerPosterSend
    {
        $chatId = $this->chatId();

        $thread = $this->threadId();

        $catatan = new PartnerPosterSend([
            'drama_id'  => $drama->id,
            'chat_id'   => (string) $chatId,
            'thread_id' => $thread,
            'sent_by'   => $pengirim?->id,
        ]);

        if ($alasan = $this->penghalang()) {
            return $this->simpan($catatan, [
                'status' => PartnerPosterSend::STATUS_FAILED,
                'error'  => $alasan,
            ]);
        }

        $poster = $this->poster($drama);

        if ($poster === null) {
            return $this->simpan($catatan, [
                'status' => PartnerPosterSend::STATUS_FAILED,
                'error'  => 'Poster tidak ditemukan.',
            ]);
        }

        try {
            $opsi = [];

            // Hanya disertakan bila memang diisi. Mengirim
            // `message_thread_id` bernilai null ke grup biasa membuat Telegram
            // menolak seluruh permintaannya.
            if ($thread !== null) {
                $opsi['message_thread_id'] = $thread;
            }

            /*
            | Caption sengaja judul saja, tanpa HTML dan tanpa tautan.
            |
            | Partner menyalin teks ini apa adanya ke TikTok dan Instagram.
            | Tautan bot yang ikut tersalin ke sana bukan cuma tidak berguna —
            | sebagian platform menurunkan jangkauan unggahan yang memuat
            | tautan keluar.
            */
            $jawaban = $this->telegram->sendPhoto(
                $chatId,
                $poster,
                $this->caption($drama),
                $opsi
            );

            /*
            | Tidak ada pemeriksaan "berhasil?" di sini, dan itu memang
            | benar: `TelegramResponse` hanya pernah dibuat untuk jawaban
            | dengan `ok: true`. Penolakan Telegram dilempar sebagai
            | `TelegramException` dan ditangkap di bawah.
            */
            return $this->simpan($catatan, [
                'message_id' => $jawaban->messageId(),
                'status'     => PartnerPosterSend::STATUS_SENT,
                'sent_at'    => now(),
            ]);

        } catch (Throwable $e) {

            return $this->simpan($catatan, [
                'status' => PartnerPosterSend::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * Teks di bawah poster.
     *
     * Dipisah jadi method sendiri supaya bentuknya bisa diubah di satu tempat
     * tanpa menyentuh alur pengiriman.
     */
    public function caption(Drama $drama): string
    {
        return trim((string) $drama->title);
    }

    /**
     * Poster sebagai berkas lokal, URL, atau null.
     *
     * Berkas lokal didahulukan: mengunggah byte-nya sendiri selalu berhasil,
     * sementara memberi URL berarti Telegram yang harus bisa menjangkau
     * server kita — yang gagal diam-diam bila situsnya sedang di belakang
     * mode pemeliharaan atau firewall.
     *
     * Method dengan isi yang sama ada di `ChannelPostService`, dan itu
     * disengaja: menariknya ke satu tempat berarti menyambungkan dua fitur
     * yang justru ingin dipisahkan, demi menghemat lima baris.
     */
    private function poster(Drama $drama): string|SplFileInfo|null
    {
        if (blank($drama->poster)) {
            return null;
        }

        $path = storage_path('app/public/'.ltrim((string) $drama->poster, '/'));

        return is_file($path) ? new SplFileInfo($path) : $drama->poster_url;
    }

    /**
     * @param  array<string, mixed>  $atribut
     */
    private function simpan(PartnerPosterSend $catatan, array $atribut): PartnerPosterSend
    {
        $catatan->fill($atribut);

        $catatan->save();

        return $catatan;
    }
}
