<?php

namespace App\Services\Telegram;

use App\Models\ChannelPost;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\User;
use App\Services\Admin\SettingService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\TelegramDeepLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengirim katalog drama ke channel Telegram.
 *
 * Menghasilkan postingan berisi poster drama beserta daftar episode, di mana
 * setiap baris adalah tautan ke bot. Pengguna yang menekannya masuk ke bot,
 * dan bot menjawab dengan aturan yang sudah ada — video langsung untuk yang
 * berhak, tawaran berlangganan untuk yang belum. Lihat `WatchHandler`.
 *
 * ## Batas 1024 karakter yang menentukan bentuk kelas ini
 *
 * Caption sebuah foto di Telegram maksimal 1024 karakter; pesan teks biasa
 * 4096. Satu baris episode memakan sekitar 45 karakter, jadi drama 60 episode
 * tidak mungkin muat dalam satu caption.
 *
 * Yang dilakukan: episode pertama masuk ke caption foto sampai batasnya
 * hampir tersentuh, sisanya menyusul sebagai pesan teks biasa. Pilihan lain —
 * memotong daftarnya diam-diam — menghasilkan postingan yang terlihat
 * lengkap padahal separuh episodenya tidak punya tautan, dan tidak ada yang
 * akan menyadarinya sampai ada yang bertanya.
 *
 * ## Episode tanpa video tidak diberi tautan
 *
 * Barisnya tetap ditulis supaya nomornya tidak melompat, tetapi tanpa
 * tautan. Tautan yang mengantar ke bot untuk dijawab "video belum siap"
 * adalah dead link yang kebetulan berpindah aplikasi dulu.
 */
class ChannelPostService
{
    /**
     * Ambang aman caption foto.
     *
     * Batas Telegram 1024, tapi yang dihitung Telegram adalah karakter UTF-16
     * setelah tag HTML dibuang — perhitungan yang tidak sepenuhnya bisa ditiru
     * dari sisi kita. Sisa 120 karakter dipakai sebagai jarak aman supaya
     * postingan tidak ditolak gara-gara selisih beberapa karakter.
     */
    private const BATAS_CAPTION = 900;

    /** Batas pesan teks biasa, dengan jarak aman yang sama. */
    private const BATAS_TEKS = 3900;

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

    /** Chat id channel tujuan, atau null bila belum diatur. */
    public function chatId(): ?string
    {
        $nilai = trim((string) $this->settings->get('channel_chat_id', ''));

        return $nilai !== '' ? $nilai : null;
    }

    public function autoPostAktif(): bool
    {
        return (string) $this->settings->get('channel_auto_post', '0') === '1';
    }

    /**
     * Alasan kenapa pengiriman belum bisa dilakukan, atau null bila siap.
     *
     * Dipakai panel admin untuk menonaktifkan tombol Kirim beserta alasannya,
     * bukan membiarkan admin menekan tombol lalu menerima kegagalan.
     */
    public function penghalang(): ?string
    {
        if (! $this->telegram->isConfigured()) {
            return 'Token bot belum diisi di .env (TELEGRAM_BOT_TOKEN).';
        }

        if ($this->chatId() === null) {
            return 'Chat ID channel belum diisi di Pengaturan → Channel Telegram.';
        }

        if (TelegramDeepLink::build('cek') === null) {
            return 'TELEGRAM_BOT_USERNAME belum diisi, jadi tautan "Tonton Sekarang" tidak bisa dibuat.';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Menyusun
    |--------------------------------------------------------------------------
    */

    /**
     * Episode yang akan masuk postingan.
     *
     * @return Collection<int,Episode>
     */
    public function episodes(Drama $drama, ?int $dari = null, ?int $sampai = null): Collection
    {
        return $drama->episodes()
            ->with('video:id,episode_id,sync_status,telegram_file_id')
            ->when($dari !== null, fn ($q) => $q->where('episode_number', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->where('episode_number', '<=', $sampai))
            ->orderBy('episode_number')
            ->get();
    }

    /**
     * Seluruh potongan pesan yang akan dikirim.
     *
     * Potongan pertama jadi caption foto, sisanya pesan teks. Dikembalikan
     * apa adanya supaya panel admin bisa menampilkan pratinjau yang PERSIS
     * sama dengan yang akan terkirim — pratinjau yang disusun dengan cara
     * berbeda dari pengirimnya adalah pratinjau yang suatu saat berbohong.
     *
     * @return array<int,string>
     */
    public function susun(Drama $drama, ?int $dari = null, ?int $sampai = null): array
    {
        $episodes = $this->episodes($drama, $dari, $sampai);

        $baris = $episodes->map(fn (Episode $e) => $this->baris($e))->all();

        $template = (string) $this->settings->get('channel_template', "『 {judul} 』\n\n{daftar}");

        // Kepala caption disusun lebih dulu tanpa daftar, supaya sisa ruang
        // untuk baris episode bisa dihitung dari panjang sebenarnya.
        $kepala = strtr($template, [
            '{judul}'          => e($drama->title),
            '{sinopsis}'       => e($this->sinopsis($drama)),
            '{negara}'         => e((string) ($drama->country?->name ?? '')),
            '{genre}'          => e($drama->genres->pluck('name')->take(3)->join(', ')),
            '{total_episode}'  => (string) ($drama->total_episode ?: $episodes->count()),
            '{tautan_drama}'   => e((string) (TelegramDeepLink::drama($drama) ?? '')),
            '{tautan_vip}'     => e((string) (TelegramDeepLink::subscribe() ?? '')),
            '{daftar}'         => '',
        ]);

        return $this->potong($this->rapikan($kepala), $baris);
    }

    /**
     * Sinopsis yang sudah dipangkas.
     *
     * Caption foto dibatasi 1024 karakter, dan sinopsis panjang memakan jatah
     * yang seharusnya jadi baris episode — hal yang justru bisa ditekan
     * pembaca. Batasnya bisa diubah admin di Pengaturan.
     */
    private function sinopsis(Drama $drama): string
    {
        $teks = trim((string) $drama->synopsis);

        if ($teks === '') {
            return '';
        }

        $batas = max(0, (int) $this->settings->get('channel_sinopsis_max', 180));

        return $batas === 0 ? '' : \Illuminate\Support\Str::limit($teks, $batas);
    }

    /**
     * Bersihkan sisa baris kosong dari placeholder yang tidak terisi.
     *
     * Drama tanpa genre atau tanpa sinopsis meninggalkan baris kosong dan
     * tanda pemisah yang menggantung — postingan yang terlihat seperti
     * template yang lupa diisi. Tiga baris kosong berturut-turut dipadatkan
     * jadi satu.
     */
    private function rapikan(string $teks): string
    {
        // Baris "Korea • 4 Episode • " — genre kosong meninggalkan pemisah
        // menggantung di ujung. Dibersihkan di kedua ujung setiap baris.
        $teks = preg_replace('/[ \t]*[•·|]+[ \t]*$/m', '', $teks) ?? $teks;
        $teks = preg_replace('/^[ \t]*[•·|]+[ \t]*/m', '', $teks) ?? $teks;

        // Baris yang isinya tinggal pemisah saja dibuang seluruhnya.
        $teks = preg_replace('/^[ \t]*[•·|-]+[ \t]*$/m', '', $teks) ?? $teks;

        // Blockquote kosong terjadi pada drama tanpa sinopsis. Yang tampil di
        // Telegram adalah kotak kutipan kosong — bekas template, bukan isi.
        $teks = str_replace("<blockquote></blockquote>\n", '', $teks);
        $teks = str_replace('<blockquote></blockquote>', '', $teks);

        return trim(preg_replace('/\n{3,}/', "\n\n", $teks) ?? $teks);
    }

    /** Satu baris episode, sudah jadi HTML siap kirim. */
    private function baris(Episode $episode): string
    {
        $format = (string) $this->settings->get('channel_line', '➤ Part {nomor} | {tanda} → {tautan}');

        $tanda = $episode->is_vip
            ? (string) $this->settings->get('channel_vip_mark', '💎')
            : (string) $this->settings->get('channel_free_mark', '🆓');

        $cta = e((string) $this->settings->get('channel_cta', 'Tonton Sekarang'));

        $siap = $episode->video?->isSyncedToTelegram() ?? false;

        $tautanUrl = $siap ? TelegramDeepLink::watch($episode) : null;

        // Judul drama dan judul episode datang dari input admin, jadi wajib
        // di-escape. Caption dikirim dengan parse_mode HTML, dan satu tanda
        // < di judul sudah cukup membuat Telegram menolak seluruh pesannya.
        return strtr($format, [
            '{nomor}'         => (string) $episode->episode_number,
            '{tanda}'         => $tanda,
            '{judul_episode}' => e((string) $episode->title),
            '{tautan}'        => $tautanUrl !== null
                ? '<a href="'.e($tautanUrl).'">'.$cta.'</a>'
                : '<i>belum tersedia</i>',
        ]);
    }

    /**
     * Bagi kepala + baris menjadi beberapa pesan yang muat.
     *
     * @param  array<int,string>  $baris
     * @return array<int,string>
     */
    private function potong(string $kepala, array $baris): array
    {
        if ($baris === []) {
            return [rtrim($kepala)];
        }

        $pesan = [];

        $sekarang = $kepala;

        $batas = self::BATAS_CAPTION;

        foreach ($baris as $satu) {

            $calon = $sekarang === '' ? $satu : $sekarang."\n".$satu;

            if (mb_strlen($calon) > $batas && $sekarang !== '') {
                $pesan[] = rtrim($sekarang);

                // Pesan kedua dan seterusnya adalah teks biasa, yang batasnya
                // jauh lebih longgar daripada caption foto.
                $sekarang = $satu;
                $batas    = self::BATAS_TEKS;

                continue;
            }

            $sekarang = $calon;
        }

        if (trim($sekarang) !== '') {
            $pesan[] = rtrim($sekarang);
        }

        return $pesan;
    }

    /*
    |--------------------------------------------------------------------------
    | Mengirim
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim satu drama ke channel.
     *
     * Selalu menghasilkan baris `ChannelPost`, berhasil maupun gagal.
     * Kegagalan yang tidak tercatat berarti admin menekan Kirim, tidak
     * melihat apa pun di channel, dan tidak punya cara mencari tahu sebabnya.
     */
    public function kirim(
        Drama $drama,
        ?int $dari = null,
        ?int $sampai = null,
        string $source = ChannelPost::SOURCE_MANUAL,
        ?User $pengirim = null
    ): ChannelPost {

        $chatId = $this->chatId();

        $catatan = new ChannelPost([
            'drama_id'      => $drama->id,
            'from_episode'  => $dari,
            'to_episode'    => $sampai,
            'chat_id'       => (string) $chatId,
            'source'        => $source,
            'sent_by'       => $pengirim?->id,
        ]);

        if ($alasan = $this->penghalang()) {
            return $this->simpan($catatan, [
                'status' => ChannelPost::STATUS_FAILED,
                'error'  => $alasan,
            ]);
        }

        $potongan = $this->susun($drama, $dari, $sampai);

        $jumlahEpisode = $this->episodes($drama, $dari, $sampai)->count();

        try {
            $ids = [];

            $poster = $this->poster($drama);

            foreach ($potongan as $i => $teks) {

                // Poster hanya ikut di pesan pertama. Mengulanginya di setiap
                // potongan membuat channel penuh gambar yang sama.
                $jawaban = ($i === 0 && $poster !== null)
                    ? $this->telegram->sendPhoto($chatId, $poster, $teks, [
                        'parse_mode' => 'HTML',
                    ])
                    : $this->telegram->sendMessage($chatId, $teks, [
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);

                if ($id = $jawaban->messageId()) {
                    $ids[] = $id;
                }
            }

            return $this->simpan($catatan, [
                'message_ids'   => $ids,
                'episode_count' => $jumlahEpisode,
                'status'        => ChannelPost::STATUS_SENT,
            ]);

        } catch (Throwable $e) {

            Log::error('channel.post_failed', [
                'drama_id' => $drama->id,
                'chat_id'  => $chatId,
                'sebab'    => $e->getMessage(),
            ]);

            return $this->simpan($catatan, [
                'status' => ChannelPost::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Simpan catatan lalu kembalikan modelnya.
     *
     * Ada karena `tap($m)->forceFill([...])->save()` — bentuk yang sekilas
     * terbaca benar — sebenarnya mengembalikan bool dari `save()`, bukan
     * modelnya. `tap()` mengembalikan targetnya hanya untuk pemanggilan
     * pertama; sesudah itu rantainya berjalan di atas model biasa.
     *
     * Akibatnya setiap pengiriman melempar TypeError sebelum satu pesan pun
     * sampai ke Telegram, dan yang terlihat admin hanya baris "Gagal" di
     * riwayat.
     */
    private function simpan(ChannelPost $catatan, array $nilai): ChannelPost
    {
        $catatan->forceFill($nilai)->save();

        return $catatan;
    }

    /**
     * Poster yang akan diunggah, atau null bila dramanya tidak punya.
     *
     * Berkasnya diunggah langsung, bukan diberikan sebagai URL. Telegram
     * memang bisa mengambil gambar dari URL, tapi itu mensyaratkan servernya
     * dapat menjangkau situs kita — dan gagal diam-diam saat situs masih di
     * localhost, di balik Cloudflare yang menantang bot, atau ketika
     * `APP_URL` belum benar. Mengunggah berkasnya menghilangkan seluruh
     * kelas kegagalan itu.
     *
     * Kalau berkasnya tidak ditemukan di disk, URL dipakai sebagai cadangan
     * terakhir daripada mengirim postingan tanpa gambar sama sekali.
     */
    private function poster(Drama $drama): string|\SplFileInfo|null
    {
        if (blank($drama->poster)) {
            return null;
        }

        $path = storage_path('app/public/'.ltrim((string) $drama->poster, '/'));

        return is_file($path) ? new \SplFileInfo($path) : $drama->poster_url;
    }

    /**
     * Apakah drama ini pernah berhasil dikirim.
     *
     * Dipakai penjagaan kiriman otomatis. Drama yang ditarik lalu
     * dipublikasikan ulang tidak boleh menghasilkan postingan kedua yang
     * identik — dan itu terjadi setiap kali admin memperbaiki sinopsis.
     */
    public function pernahDikirim(Drama $drama): bool
    {
        return ChannelPost::query()
            ->where('drama_id', $drama->id)
            ->where('status', ChannelPost::STATUS_SENT)
            ->exists();
    }
}
