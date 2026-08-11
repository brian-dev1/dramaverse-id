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

    case INTL = 'INTL';

    /** Label untuk tombol dan panel admin. */
    public function label(): string
    {
        return match ($this) {
            self::ID   => 'Indonesia',
            self::INTL => 'Luar Indonesia',
        };
    }

    /** Kalimat pada tombol pilihan di bot. */
    public function tombol(): string
    {
        return match ($this) {
            self::ID   => 'Bayar dari Indonesia',
            self::INTL => 'Bayar dari luar Indonesia',
        };
    }

    /** Keterangan singkat, dipakai panel admin dan pesan bot. */
    public function keterangan(): string
    {
        return match ($this) {
            self::ID   => 'QRIS Indonesia, transfer bank lokal, e-wallet Indonesia.',
            self::INTL => 'Malaysia dan negara lain — DuitNow QR dan transfer luar negeri.',
        };
    }

    /**
     * Mata uang yang lazim, dipakai sebagai nilai awal form paket.
     *
     * Hanya saran, bukan aturan. Mata uang sebenarnya disimpan per paket
     * karena wilayah INTL suatu saat memuat paket Singapura di samping paket
     * Malaysia.
     */
    public function mataUangBawaan(): string
    {
        return match ($this) {
            self::ID   => 'IDR',
            self::INTL => 'MYR',
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
