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
 * 4096. Satu baris episode memakan sekitar 30 karakter yang terlihat, jadi
 * drama 60 episode tidak mungkin muat dalam satu caption.
 *
 * Yang dilakukan: episode pertama masuk ke caption foto sampai batasnya
 * hampir tersentuh, sisanya menyusul sebagai pesan teks biasa. Pilihan lain —
 * memotong daftarnya diam-diam — menghasilkan postingan yang terlihat
 * lengkap padahal separuh episodenya tidak punya tautan, dan tidak ada yang
 * akan menyadarinya sampai ada yang bertanya.
 *
 * Yang dihitung Telegram adalah teks yang TERLIHAT — tag HTML dan URL di
 * dalam href tidak ikut. Perbedaannya besar: postingan drama 4 episode
 * berukuran 1281 karakter mentah hanya 807 menurut hitungan Telegram, dan
 * mengukurnya dengan `mb_strlen()` biasa memecah postingan yang sebenarnya
 * muat dengan sisa 200 karakter. Lihat `panjangTelegram()`.
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
     * Batas Telegram 1024, diukur dengan `panjangTelegram()` yang meniru cara
     * Telegram menghitung. Sisa 24 karakter cukup sebagai jarak aman karena
     * hitungannya sudah setara, bukan tebakan.
     */
    private const BATAS_CAPTION = 1000;

    /** Batas pesan teks biasa (4096), dengan jarak aman yang sama. */
    private const BATAS_TEKS = 4000;

    /** Penanda tempat daftar episode disisipkan di dalam template. */
    private const PENANDA_DAFTAR = '{daftar}';

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

        $kepala = $this->kepala($drama, $episodes);

        /*
        | Kepala yang sendirian saja sudah melewati batas caption.
        |
        | Ini yang dulu menghasilkan "Bad Request: message caption is too
        | long" di riwayat: `potong()` di bawah memang menyerah pada keadaan
        | ini — komentarnya bahkan menyebutkannya — lalu mengirim kepala itu
        | apa adanya sebagai caption. Telegram menolak seluruh postingannya,
        | dan yang terlihat admin cuma satu baris merah tanpa petunjuk drama
        | mana yang sinopsisnya kepanjangan.
        |
        | Yang dipangkas duluan sinopsis, bukan daftar episode. Sinopsis
        | adalah satu-satunya bagian yang panjangnya datang dari isi drama
        | dan tidak ada yang bisa ditekan pembacanya; baris episode justru
        | seluruh gunanya postingan ini. Dipangkas bertahap, bukan langsung
        | dibuang, supaya postingan yang cuma lewat sedikit tetap punya
        | sinopsis.
        */
        foreach ([120, 60, 0] as $batasSinopsis) {

            if ($this->panjangTelegram($this->sisipkan($kepala, [])) <= self::BATAS_CAPTION) {
                break;
            }

            $kepala = $this->kepala($drama, $episodes, $batasSinopsis);
        }

        return $this->potong($kepala, $baris);
    }

    /**
     * Bagian tetap postingan: template yang placeholder-nya sudah terisi.
     *
     * `{daftar}` sengaja TIDAK diisi di sini. Ia dibiarkan utuh sampai
     * `potong()`, supaya daftar episode disisipkan tepat di posisi yang
     * ditulis admin di template — bukan ditempel di ujung caption, yang
     * membuat dua garis pemisah di template bawaan berdiri kosong
     * berdempetan dan daftarnya muncul di bawah blok penutup.
     *
     * @param  Collection<int,Episode>  $episodes
     * @param  int|null  $batasSinopsis  null = pakai angka dari Pengaturan
     */
    private function kepala(Drama $drama, Collection $episodes, ?int $batasSinopsis = null): string
    {
        $template = (string) $this->settings->get('channel_template', "『 {judul} 』\n\n{daftar}");

        $isi = strtr($template, [
            '{judul}'          => e($drama->title),
            '{sinopsis}'       => e($this->sinopsis($drama, $batasSinopsis)),
            '{negara}'         => e((string) ($drama->country?->name ?? '')),
            '{genre}'          => e($drama->genres->pluck('name')->take(3)->join(', ')),
            '{total_episode}'  => (string) ($drama->total_episode ?: $episodes->count()),
            '{tautan_drama}'   => e((string) (TelegramDeepLink::drama($drama) ?? '')),
            '{tautan_vip}'     => e((string) (TelegramDeepLink::subscribe() ?? '')),

            /*
            | Tiga tautan berikut membuka MINI APP, bukan browser.
            |
            | Mencari judul dan mengetik permintaan sama-sama butuh kolom teks
            | dan daftar hasil — dua hal yang di dalam chat bot berarti
            | percakapan bolak-balik, sementara di satu halaman cukup satu
            | layar. Halaman itu sekarang dibuka sebagai Mini App lewat
            | `?startapp=`, jadi pembacanya tidak pernah keluar dari Telegram:
            | tautan http biasa di postingan channel dibuka Telegram di
            | browser luar, dan di sana orangnya kehilangan sesi Mini App-nya
            | sekaligus harus masuk ulang.
            |
            | `?? route(...)` hanya jaring pengaman untuk pemasangan yang
            | username botnya belum diisi. Pada keadaan itu penghalang() sudah
            | menolak pengiriman lebih dulu, jadi tautan situs tidak pernah
            | benar-benar sampai ke channel — ia hanya menjaga pratinjau di
            | panel admin tetap punya alamat yang bisa diklik.
            */
            '{tautan_cari}'    => e((string) (TelegramDeepLink::app(TelegramDeepLink::APP_CARI) ?? route('web.search'))),
            '{tautan_request}' => e((string) (TelegramDeepLink::app(TelegramDeepLink::APP_REQUEST) ?? route('web.request.index'))),
            '{tautan_situs}'   => e((string) (TelegramDeepLink::app() ?? route('web.home'))),
        ]);

        return $this->rapikan($isi);
    }

    /**
     * Sinopsis yang sudah dipangkas.
     *
     * Caption foto dibatasi 1024 karakter, dan sinopsis panjang memakan jatah
     * yang seharusnya jadi baris episode — hal yang justru bisa ditekan
     * pembaca. Batasnya bisa diubah admin di Pengaturan.
     */
    private function sinopsis(Drama $drama, ?int $batas = null): string
    {
        $teks = trim((string) $drama->synopsis);

        if ($teks === '') {
            return '';
        }

        // Angka dari pemanggil menang atas Pengaturan. Yang memakainya cuma
        // `susun()`, saat kepala postingan harus dipendekkan agar muat di
        // caption — dan pada saat itu batas yang dipilih admin sudah terbukti
        // terlalu longgar untuk drama ini.
        $batas ??= (int) $this->settings->get('channel_sinopsis_max', 180);

        $batas = max(0, $batas);

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
        /*
        | Modifier `u` wajib ada di setiap pola di bawah.
        |
        | Tanpanya PCRE bekerja per byte, bukan per karakter, sehingga kelas
        | [•·|] sebenarnya berarti "salah satu dari byte E2, 80, A2, C2, B7,
        | atau |". Byte E2 adalah byte pertama • (U+2022) sekaligus byte
        | pertama ━ (U+2501) — garis pemisah di template bawaan. Baris
        | "━━━━━━━━━━━━━━━" karena itu kehilangan satu byte E2 di depan dan
        | menyisakan 94 81 yang berdiri sendiri: caption yang bukan UTF-8 sah
        | lagi, dan Telegram menolak seluruh postingannya dengan
        | "Bad Request: text must be encoded in UTF-8" — pesan yang tidak
        | menyebut baris mana pun.
        */

        // Baris "Korea • 4 Episode • " — genre kosong meninggalkan pemisah
        // menggantung di ujung. Dibersihkan di kedua ujung setiap baris.
        $teks = preg_replace('/[ \t]*[•·|]+[ \t]*$/mu', '', $teks) ?? $teks;
        $teks = preg_replace('/^[ \t]*[•·|]+[ \t]*/mu', '', $teks) ?? $teks;

        // Baris yang isinya tinggal pemisah saja dibuang seluruhnya.
        $teks = preg_replace('/^[ \t]*[•·|-]+[ \t]*$/mu', '', $teks) ?? $teks;

        // Blockquote kosong terjadi pada drama tanpa sinopsis. Yang tampil di
        // Telegram adalah kotak kutipan kosong — bekas template, bukan isi.
        $teks = str_replace("<blockquote></blockquote>\n", '', $teks);
        $teks = str_replace('<blockquote></blockquote>', '', $teks);

        return trim(preg_replace('/\n{3,}/u', "\n\n", $teks) ?? $teks);
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
     * Panjang teks menurut hitungan Telegram.
     *
     * Telegram menghitung teks yang TERLIHAT setelah HTML-nya diurai: tag
     * dibuang, entitas dikembalikan ke karakter aslinya, dan URL di dalam
     * href tidak ikut sama sekali. Satuannya UTF-16, sehingga emoji seperti
     * 🎬 dihitung 2 — itulah kenapa `mb_strlen()` saja tidak cukup.
     *
     * Publik karena pratinjau di panel admin memakai angka yang sama. Dua
     * cara menghitung yang berbeda antara pratinjau dan pengirim berarti
     * pratinjau yang suatu saat berbohong soal jumlah pesan.
     */
    public function panjangTelegram(string $html): int
    {
        $terlihat = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return (int) (strlen(mb_convert_encoding($terlihat, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /** Batas caption foto, untuk ditampilkan di pratinjau admin. */
    public function batasCaption(): int
    {
        return self::BATAS_CAPTION;
    }

    /**
     * Sisipkan daftar episode ke posisi {daftar} di dalam kepala.
     *
     * @param  array<int,string>  $baris
     */
    private function sisipkan(string $kepala, array $baris): string
    {
        $daftar = implode("\n", $baris);

        // Template yang penandanya dihapus admin tetap harus menghasilkan
        // postingan yang utuh; daftarnya ditempel di ujung seperti dulu.
        if (! str_contains($kepala, self::PENANDA_DAFTAR)) {
            return $daftar === '' ? rtrim($kepala) : rtrim(rtrim($kepala)."\n".$daftar);
        }

        if ($daftar !== '') {
            return rtrim(str_replace(self::PENANDA_DAFTAR, $daftar, $kepala));
        }

        // Tanpa satu pun baris, barisnya dibuang seluruhnya. Mengganti
        // penanda dengan string kosong meninggalkan baris kosong di antara
        // dua garis pemisah — bekas template yang terlihat seperti isi yang
        // gagal dimuat.
        $kepala = preg_replace(
            '/^[ \t]*'.preg_quote(self::PENANDA_DAFTAR, '/').'[ \t]*\R?/mu',
            '',
            $kepala
        ) ?? str_replace(self::PENANDA_DAFTAR, '', $kepala);

        return trim(preg_replace('/\n{3,}/u', "\n\n", $kepala) ?? $kepala);
    }

    /**
     * Bagi kepala + baris menjadi beberapa pesan yang muat.
     *
     * Caption diisi sebanyak yang muat, sisanya jadi pesan teks. Yang diukur
     * setiap kali adalah caption UTUH hasil penyisipan, bukan potongannya —
     * karena panjang yang dihitung Telegram baru bisa diketahui setelah
     * penandanya diganti.
     *
     * @param  array<int,string>  $baris
     * @return array<int,string>
     */
    private function potong(string $kepala, array $baris): array
    {
        $tanpaDaftar = $this->sisipkan($kepala, []);

        /*
        | Kepala yang tetap tidak muat walau sinopsisnya sudah dibuang.
        |
        | Artinya templatenya sendiri yang panjang — dan tidak ada yang bisa
        | dipangkas lagi tanpa membuang tulisan yang sengaja ditulis admin.
        | Jalan keluarnya bukan memaksakan caption, melainkan mengirim
        | fotonya TANPA caption dan menurunkan seluruh isinya menjadi pesan
        | teks, yang batasnya 4096 — empat kali lipat.
        |
        | Potongan pertama dikembalikan sebagai string kosong, dan `kirim()`
        | membacanya sebagai "foto saja". Postingannya jadi sedikit berbeda
        | bentuk: gambar dulu, teks menyusul. Itu harga yang jauh lebih murah
        | daripada postingan yang ditolak Telegram seluruhnya.
        */
        if ($this->panjangTelegram($tanpaDaftar) > self::BATAS_CAPTION) {

            return array_merge(
                [''],
                $this->pecahTeks($this->sisipkan($kepala, $baris), self::BATAS_TEKS)
            );
        }

        if ($baris === []) {
            return [$tanpaDaftar];
        }

        $muat = [];

        $sisa = $baris;

        while ($sisa !== []) {

            $calon = [...$muat, $sisa[0]];

            if ($this->panjangTelegram($this->sisipkan($kepala, $calon)) > self::BATAS_CAPTION) {
                break;
            }

            $muat = $calon;

            array_shift($sisa);
        }

        // $muat bisa kosong bila kepalanya sendiri sudah hampir memenuhi
        // 1024 — template yang terlalu panjang. Postingannya tetap dikirim;
        // seluruh daftar menyusul sebagai pesan teks.
        $pesan = [$this->sisipkan($kepala, $muat)];

        $sekarang = '';

        foreach ($sisa as $satu) {

            $calon = $sekarang === '' ? $satu : $sekarang."\n".$satu;

            if ($this->panjangTelegram($calon) > self::BATAS_TEKS && $sekarang !== '') {
                $pesan[] = rtrim($sekarang);

                $sekarang = $satu;

                continue;
            }

            $sekarang = $calon;
        }

        if (trim($sekarang) !== '') {
            $pesan[] = rtrim($sekarang);
        }

        return $pesan;
    }

    /**
     * Bagi satu teks panjang menjadi beberapa pesan yang muat.
     *
     * Dipotong di pergantian baris, tidak pernah di tengah baris: satu baris
     * episode berisi tag `<a href>` yang utuh, dan memotongnya di tengah
     * menghasilkan HTML rusak yang ditolak Telegram — kegagalan yang sama
     * persis dengan yang hendak dihindari fungsi ini.
     *
     * Satu baris yang sendirian saja melewati batas tetap dikirim apa
     * adanya. Baris episode berkisar 30-100 karakter, jadi keadaan itu
     * menuntut judul episode sepanjang empat ribu karakter; memotongnya
     * paksa di sana justru merusak yang tadinya masih mungkin terkirim.
     *
     * @return array<int,string>
     */
    private function pecahTeks(string $teks, int $batas): array
    {
        $pesan = [];

        $sekarang = '';

        foreach (explode("\n", $teks) as $baris) {

            $calon = $sekarang === '' ? $baris : $sekarang."\n".$baris;

            if ($this->panjangTelegram($calon) > $batas && $sekarang !== '') {
                $pesan[] = rtrim($sekarang);

                $sekarang = $baris;

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

                /*
                | Potongan kosong = caption sengaja dikosongkan oleh
                | `potong()` karena kepalanya tidak muat. Fotonya tetap
                | dikirim (TelegramService memang menghilangkan caption
                | kosong dari payload), tapi kalau dramanya tidak punya
                | poster sama sekali, tidak ada apa pun untuk dikirim —
                | `sendMessage` dengan teks kosong hanya menghasilkan
                | "message text is empty" dari Telegram.
                */
                if ($teks === '' && ! ($i === 0 && $poster !== null)) {
                    continue;
                }

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
