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

    public function render(): string
    {
        $judul = trim($this->ikon.' <b>'.e(mb_strtoupper($this->judul)).'</b>');

        $keluar = $judul;
        $sebelumnya = 'judul';

        foreach ($this->blok as [$jenis, $teks]) {

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
