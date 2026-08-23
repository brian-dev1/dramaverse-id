<?php

namespace App\Support\Telegram;

/**
 * Penyusun satu pesan pemberitahuan bot dengan bentuk yang selalu sama.
 *
 * ## Kenapa ada
 *
 * Setiap tempat yang mengirim kabar ke pengguna dulu menyusun teksnya
 * sendiri: ada yang memakai "<b>Paket</b>: ...", ada yang "Paket: ...",
 * ada yang judulnya huruf kecil, ada yang huruf besar. Hasilnya, dua pesan
 * yang datang berurutan terlihat seperti berasal dari dua aplikasi berbeda.
 *
 * Bentuk pesan adalah bagian dari produk, bukan detail masing-masing
 * pemanggil. Jadi bentuknya ditentukan satu kali di sini, dan pemanggil
 * hanya menyerahkan isinya.
 *
 * ## Kenapa rinciannya di dalam <pre>
 *
 * Nilai yang sejajar jauh lebih cepat dibaca daripada nilai yang menggantung
 * di ujung label dengan panjang berbeda-beda. Telegram memakai huruf lebar
 * tetap di dalam <pre>, sehingga label yang di-pad dengan spasi benar-benar
 * berbaris lurus di layar — hal yang tidak bisa dijamin di luar blok itu.
 *
 * ## Semua nilai di-escape
 *
 * Nama paket dan alasan kegagalan berasal dari basis data dan dari penyedia
 * pembayaran. Satu tanda "<" di sana cukup untuk membuat Telegram menolak
 * seluruh pesan dengan galat parse — jadi escaping dilakukan di sini, bukan
 * dipercayakan kepada pemanggil untuk diingat.
 *
 * Contoh keluaran:
 *
 *   ✅ <b>PEMBAYARAN BERHASIL</b>
 *   <blockquote>Membership Anda sudah aktif.</blockquote>
 *   <pre>Paket         : VIP Nebula
 *   Dibayar       : Rp 1.000
 *   Tagihan       : INV-20260807-0YA41Y
 *   Berlaku sampai: 09 Aug 2026</pre>
 *   <i>Selamat menonton!</i>
 */
final class Notice
{
    /**
     * Garis pemisah antarbagian.
     *
     * Telegram tidak punya elemen pemisah, jadi dipakai huruf gambar kotak.
     * Panjangnya sengaja tidak selebar layar: garis yang terlalu panjang
     * membungkus ke baris kedua di ponsel sempit dan justru terlihat berantakan.
     */
    private const GARIS = '━━━━━━━━━━━━━━━━━━';

    /** Garis pemisah antarbutir di dalam satu bagian; sengaja lebih tipis. */
    private const GARIS_TIPIS = '──────────────────';

    /**
     * Blok isi, masing-masing berpasangan [jenis, teks].
     *
     * Jenisnya disimpan karena jarak antarblok tidak seragam: sub-judul
     * harus menempel pada isi yang diperkenalkannya, sedangkan blok lain
     * dipisah satu baris kosong. Tanpa penanda jenis, keduanya tidak bisa
     * dibedakan lagi saat dirangkai.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private array $blok = [];

    private function __construct(
        private readonly string $ikon,
        private readonly string $judul
    ) {
    }

    public static function make(string $ikon, string $judul): self
    {
        return new self($ikon, $judul);
    }

    /**
     * Sub-judul untuk pesan yang berisi lebih dari satu bagian.
     *
     * Dipakai halaman seperti Profil, yang memuat langganan, tagihan, dan
     * aktivitas sekaligus. Tanpa pemisah, ketiganya terbaca sebagai satu
     * daftar panjang dan pengguna harus memilah sendiri mana milik mana.
     */
    public function section(string $ikon, string $judul): self
    {
        $this->blok[] = ['section', trim($ikon.' <b>'.e($judul).'</b>')];

        return $this;
    }

    /** Satu kalimat pembuka, ditampilkan sebagai kutipan. */
    public function lead(string $teks): self
    {
        $this->blok[] = ['lead', '<blockquote>'.e($teks).'</blockquote>'];

        return $this;
    }

    /**
     * Tabel label-nilai yang sejajar.
     *
     * Nilai null dibuang, bukan dicetak sebagai baris kosong: baris
     * "Berlaku sampai: -" memunculkan pertanyaan yang tidak perlu ada.
     *
     * @param  array<string,string|null>  $data
     */
    public function rows(array $data): self
    {
        $data = array_filter($data, static fn ($v) => $v !== null && $v !== '');

        if ($data === []) {
            return $this;
        }

        $lebar = max(array_map(static fn ($k) => mb_strlen((string) $k), array_keys($data)));

        $baris = [];

        foreach ($data as $label => $nilai) {
            $pad = str_repeat(' ', $lebar - mb_strlen((string) $label));
            $baris[] = e((string) $label).$pad.' : '.e((string) $nilai);
        }

        $this->blok[] = ['rows', '<pre>'.implode("\n", $baris).'</pre>'];

        return $this;
    }

    /**
     * Satu bagian bercabang, yang menyatu dengan bagian bercabang di sebelahnya.
     *
     * ## Bedanya dengan `section()` + `rows()`
     *
     * Pasangan itu membuat SATU BLOK `<pre>` untuk setiap bagian. Di layar,
     * empat bagian jadi empat kotak terpisah dengan jarak di antaranya —
     * bentuk yang benar untuk pesan berisi dua bagian, tetapi berantakan
     * untuk halaman seperti Profil yang memuat lima sekaligus. Mata membaca
     * lima kotak sebagai lima pesan, bukan satu halaman.
     *
     * `branch()` menaruh judul dan isinya di dalam blok yang SAMA, dan
     * bagian-bagian bercabang yang berurutan digabung jadi satu `<pre>`
     * dengan garis tipis sebagai batas. Hasilnya satu halaman utuh yang
     * tetap terbagi jelas.
     *
     * Penanda `├` dan `└` menggantikan sekat kotak: batas bagian ditandai
     * garis, batas baris terakhir ditandai bentuk cabangnya sendiri.
     *
     * Pemisahannya otomatis putus bila ada blok lain di antaranya — catatan
     * peringatan, misalnya. Itu disengaja: sesuatu yang perlu menonjol tidak
     * boleh ikut terkubur di dalam blok yang sama.
     *
     * @param  array<string,string|null>  $data  nilai null dibuang
     */
    public function branch(string $ikon, string $judul, array $data): self
    {
        $data = array_filter($data, static fn ($v) => $v !== null && $v !== '');

        if ($data === []) {
            return $this;
        }

        $lebar = max(array_map(static fn ($k) => mb_strlen((string) $k), array_keys($data)));

        $baris = [e(trim($ikon.' '.$judul))];

        $sisa = count($data);

        foreach ($data as $label => $nilai) {

            $sisa--;

            $pad = str_repeat(' ', $lebar - mb_strlen((string) $label));

            $baris[] = ($sisa === 0 ? '└ ' : '├ ')
                .e((string) $label).$pad.' : '.e((string) $nilai);
        }

        $this->blok[] = ['branch', implode("\n", $baris)];

        return $this;
    }

    /**
     * Daftar berpoin untuk konsekuensi atau langkah lanjutan.
     *
     * @param  array<int,string>  $item
     */
    public function bullets(array $item): self
    {
        $item = array_values(array_filter($item, static fn ($v) => filled($v)));

        if ($item === []) {
            return $this;
        }

        $this->blok[] = ['bullets', implode("\n", array_map(
            static fn (string $t) => '• '.e($t),
            $item
        ))];

        return $this;
    }

    /**
     * Daftar butir bertingkat: judul, keterangan pendek, lalu uraian.
     *
     * ## Kenapa bukan `bullets()`
     *
     * Butir berpoin cocok untuk kalimat pendek. Paket berlangganan bukan
     * kalimat pendek — namanya, harganya, masa berlakunya, dan penjelasannya
     * dijejalkan ke satu baris yang lalu membungkus dua sampai tiga kali di
     * ponsel. Hasilnya, batas antarpaket hilang: mata tidak bisa lagi
     * membedakan baris kedua paket pertama dari baris pertama paket kedua,
     * dan seluruh daftar terbaca sebagai satu paragraf.
     *
     * Di sini tiap butir mendapat tiga baris dengan peran tetap dan sebuah
     * garis pemisah, sehingga membandingkan dua paket cukup dengan melompati
     * garis, bukan menghitung ulang di mana satu paket berakhir.
     *
     * Baris kosong TIDAK dipakai sebagai pemisah: Telegram merapatkan jarak
     * antarbaris, jadi satu baris kosong terlihat hampir sama dengan jarak
     * biasa — sementara garis terlihat sebagai batas pada pandangan pertama.
     *
     * @param  array<int,array{judul:string,meta?:string|null,teks?:string|null}>  $item
     */
    public function cards(array $item): self
    {
        $kartu = [];

        foreach ($item as $satu) {

            $judul = trim((string) ($satu['judul'] ?? ''));

            if ($judul === '') {
                continue;
            }

            $baris = ['<b>'.e($judul).'</b>'];

            foreach (['meta', 'teks'] as $bagian) {

                $nilai = trim((string) ($satu[$bagian] ?? ''));

                if ($nilai !== '') {
                    $baris[] = $bagian === 'meta'
                        ? '<code>'.e($nilai).'</code>'
                        : e($nilai);
                }
            }

            $kartu[] = implode("\n", $baris);
        }

        if ($kartu === []) {
            return $this;
        }

        $this->blok[] = ['cards', implode("\n".self::GARIS_TIPIS."\n", $kartu)];

        return $this;
    }

    /**
     * Garis pemisah yang dipasang sendiri.
     *
     * Sub-judul sudah mendapat garisnya secara otomatis, jadi ini hanya untuk
     * memisahkan dua blok yang sama-sama tanpa judul.
     */
    public function divider(): self
    {
        $this->blok[] = ['divider', self::GARIS];

        return $this;
    }

    /** Paragraf biasa. Boleh dipanggil berkali-kali. */
    public function text(string $teks): self
    {
        if (filled($teks)) {
            $this->blok[] = ['text', e($teks)];
        }

        return $this;
    }

    /** Kalimat penutup, dicetak miring. */
    public function note(string $teks): self
    {
        if (filled($teks)) {
            $this->blok[] = ['note', '<i>'.e($teks).'</i>'];
        }

        return $this;
    }

    /** Kode yang perlu disalin pengguna, berdiri sendiri agar mudah ditekan. */
    public function code(string $teks): self
    {
        if (filled($teks)) {
            $this->blok[] = ['code', '<code>'.e($teks).'</code>'];
        }

        return $this;
    }

    /**
     * Satukan blok bercabang yang berurutan jadi satu `<pre>`.
     *
     * Dikerjakan di sini, bukan saat `branch()` dipanggil, karena saat itu
     * belum diketahui apakah blok berikutnya juga bercabang. Menyusunnya di
     * muka berarti menebak, dan tebakan yang salah menghasilkan dua kotak
     * yang seharusnya satu.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function gabungCabang(): array
    {
        $hasil = [];

        $kumpul = [];

        $simpan = function () use (&$hasil, &$kumpul): void {

            if ($kumpul === []) {
                return;
            }

            $hasil[] = ['rows', '<pre>'
                .implode("\n".self::GARIS_TIPIS."\n", $kumpul)
                .'</pre>'];

            $kumpul = [];
        };

        foreach ($this->blok as [$jenis, $teks]) {

            if ($jenis === 'branch') {
                $kumpul[] = $teks;

                continue;
            }

            $simpan();

            $hasil[] = [$jenis, $teks];
        }

        $simpan();

        return $hasil;
    }

    public function render(): string
    {
        $judul = trim($this->ikon.' <b>'.e(mb_strtoupper($this->judul)).'</b>');

        $keluar = $judul;
        $sebelumnya = 'judul';

        foreach ($this->gabungCabang() as $urutan => [$jenis, $teks]) {

            /*
            |------------------------------------------------------------------
            | Setiap sub-judul didahului garis
            |------------------------------------------------------------------
            |
            | Pesan bot sering memuat tiga sampai empat bagian sekaligus —
            | status, tagihan, daftar paket, catatan. Dipisah baris kosong
            | saja, keempatnya terbaca sebagai satu tumpukan panjang dan
            | pengguna harus memilah sendiri mana milik mana.
            |
            | Garisnya dipasang di sini, bukan diserahkan ke pemanggil, karena
            | konsistensi bentuk itulah yang membuat pemisahnya berguna: garis
            | yang kadang ada kadang tidak berhenti menjadi penanda.
            |
            | Kecuali bila sub-judulnya blok pertama — di situ judul pesan
            | sudah menjadi batas, dan garis tepat di bawahnya cuma pagar
            | ganda.
            |
            */

            if ($jenis === 'section' && $urutan > 0 && $sebelumnya !== 'divider') {

                $keluar .= "\n\n".self::GARIS;

                // Ditandai 'section' supaya judulnya menempel pada garisnya,
                // bukan mengambang satu baris kosong di bawahnya.
                $sebelumnya = 'section';
            }

            // Sub-judul menempel pada blok sesudahnya; sisanya diberi napas.
            $keluar .= ($sebelumnya === 'section' ? "\n" : "\n\n").$teks;

            $sebelumnya = $jenis;
        }

        return $keluar;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
