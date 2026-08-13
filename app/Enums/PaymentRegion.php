<?php

namespace App\Enums;

/**
 * Wilayah pembayaran.
 *
 * Menentukan dua hal sekaligus: paket mana yang ditawarkan kepada seseorang,
 * dan lewat provider mana ia membayarnya. Keduanya harus cocok — menawarkan
 * paket berharga Ringgit lalu memberinya QRIS Indonesia menghasilkan tagihan
 * yang tidak mungkin dibayar.
 *
 * ## Kenapa pengguna memilih sendiri
 *
 * Wilayah TIDAK ditebak dari `language_code` Telegram maupun dari IP. Banyak
 * orang Indonesia memakai Telegram berbahasa Inggris, dan banyak orang
 * Indonesia yang bekerja di Malaysia justru perlu membayar dengan cara
 * Malaysia. Yang menentukan bukan asal orangnya, melainkan alat bayar yang
 * ada di tangannya — dan itu satu-satunya hal yang tidak bisa ditebak
 * sistem, tapi selalu diketahui orangnya.
 */
enum PaymentRegion: string
{
    case ID = 'ID';

    case MY = 'MY';

    case INTL = 'INTL';

    /**
     * Label untuk tombol dan panel admin.
     *
     * ## Kenapa Malaysia dipisah dari "negara lain"
     *
     * Dulu keduanya satu wilayah bernama INTL, dan itu benar selama Malaysia
     * adalah satu-satunya pasar di luar Indonesia. Begitu daftar harganya
     * berbeda per negara, penggabungan itu memaksa satu daftar melayani dua
     * mata uang: pengunjung Malaysia melihat paket Singapura di sela paket
     * Ringgit-nya, dan tidak ada cara menyembunyikan yang bukan miliknya.
     *
     * INTL tetap ada dan tetap berguna — ia menampung negara yang belum
     * cukup ramai untuk punya daftar sendiri. Yang berubah hanya artinya:
     * dari "semua yang bukan Indonesia" menjadi "yang belum punya wilayahnya
     * sendiri".
     */
    public function label(): string
    {
        return match ($this) {
            self::ID   => 'Indonesia',
            self::MY   => 'Malaysia',
            self::INTL => 'Negara lain',
        };
    }

    /** Kalimat pada tombol pilihan di bot. */
    public function tombol(): string
    {
        return match ($this) {
            self::ID   => 'Bayar dari Indonesia',
            self::MY   => 'Bayar dari Malaysia',
            self::INTL => 'Bayar dari negara lain',
        };
    }

    /** Keterangan singkat, dipakai panel admin dan pesan bot. */
    public function keterangan(): string
    {
        return match ($this) {
            self::ID   => 'QRIS Indonesia, transfer bank lokal, e-wallet Indonesia.',
            self::MY   => 'DuitNow QR, transfer bank Malaysia, e-wallet Malaysia.',
            self::INTL => 'Selain Indonesia dan Malaysia — transfer luar negeri.',
        };
    }

    /**
     * Mata uang yang lazim, dipakai sebagai nilai awal form paket.
     *
     * Hanya saran, bukan aturan. Mata uang sebenarnya disimpan per paket:
     * wilayah INTL memuat negara yang mata uangnya berbeda-beda, dan bahkan
     * satu negara bisa punya paket dalam dua mata uang.
     */
    public function mataUangBawaan(): string
    {
        return match ($this) {
            self::ID   => 'IDR',
            self::MY   => 'MYR',
            self::INTL => 'USD',
        };
    }

    /**
     * Bendera, sebagai penanda visual di daftar panel dan pemilih wilayah.
     *
     * Warna dan bentuk terbaca lebih cepat daripada teks saat mata menyapu
     * daftar — dan di halaman harga, wilayah adalah hal pertama yang harus
     * dipastikan pengunjung sebelum ia membaca satu angka pun.
     */
    public function bendera(): string
    {
        return match ($this) {
            self::ID   => '🇮🇩',
            self::MY   => '🇲🇾',
            self::INTL => '🌏',
        };
    }

    /** @return array<string,string> nilai => label, untuk dropdown. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }

    /** Wilayah dari string apa pun; jatuh ke Indonesia bila tidak dikenal. */
    public static function fromAny(mixed $nilai): self
    {
        return self::tryFrom((string) $nilai) ?? self::ID;
    }
}
