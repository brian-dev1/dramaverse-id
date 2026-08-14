<?php

namespace App\Support;

/**
 * Menulis nominal beserta mata uangnya.
 *
 * ## Kenapa ini ada
 *
 * Sebelum ada wilayah pembayaran kedua, `'Rp '.number_format($n, 0, ',', '.')`
 * ditulis ulang di dua puluhan tempat. Selama hanya ada Rupiah itu tidak
 * merugikan siapa pun. Begitu Ringgit masuk, setiap tempat itu berubah jadi
 * kebohongan: tagihan MYR 15 tampil sebagai "Rp 15" — angka yang sepuluh ribu
 * kali lebih kecil dari yang sebenarnya harus dibayar, di layar orang yang
 * sedang memutuskan membayar atau tidak.
 *
 * Karena itu nominal TIDAK pernah dicetak tanpa mata uangnya. Fungsi ini
 * mewajibkan keduanya disebut bersamaan.
 *
 * ## Pecahan
 *
 * Rupiah dibulatkan tanpa desimal — sen Rupiah tidak dipakai di mana pun, dan
 * "Rp 50.000,00" hanya menambah panjang tanpa menambah keterangan. Ringgit
 * memakai dua desimal karena sen Ringgit nyata: RM 14,90 adalah harga yang
 * lazim ditulis begitu, dan membulatkannya jadi RM 15 mengubah harga.
 */
class Uang
{
    /**
     * Awalan dan jumlah desimal per mata uang.
     *
     * Mata uang yang tidak terdaftar dicetak apa adanya: "SGD 12.00". Itu
     * pilihan yang disengaja — lebih baik format yang kaku tapi benar
     * daripada menebak simbol dan salah menampilkan mata uang negara lain.
     *
     * @var array<string,array{0:string,1:int}>
     */
    private const FORMAT = [
        'IDR' => ['Rp ', 0],
        'MYR' => ['RM ', 2],
        // Dolar ditulis dengan kode negaranya, bukan "$" telanjang. Belasan
        // mata uang memakai lambang yang sama — dolar Amerika, Singapura,
        // Australia, Hong Kong — dan "$12" di halaman yang juga melayani
        // Malaysia adalah angka yang tidak bisa dipastikan artinya oleh orang
        // yang sedang memutuskan membayar.
        'USD' => ['US$ ', 2],
        'SGD' => ['S$ ', 2],
    ];

    /** Mata uang yang boleh dipilih admin saat membuat paket. */
    public const PILIHAN = [
        'IDR' => 'Rupiah (Rp)',
        'MYR' => 'Ringgit Malaysia (RM)',
        'SGD' => 'Dolar Singapura (SGD)',
        'USD' => 'Dolar Amerika (USD)',
    ];

    /**
     * Nominal siap tampil.
     *
     * Mata uang boleh null demi baris lama yang belum punya kolomnya —
     * dianggap Rupiah, sama seperti nilai bawaan kolomnya di database.
     */
    public static function format(float|int|string|null $nominal, ?string $mataUang = 'IDR'): string
    {
        $kode = strtoupper(trim((string) ($mataUang ?: 'IDR')));

        [$awalan, $desimal] = self::FORMAT[$kode] ?? [$kode.' ', 2];

        // Pemisah ribuan titik dan desimal koma dipakai baik di Indonesia
        // maupun Malaysia untuk Rupiah; untuk mata uang lain dipakai gaya
        // internasional supaya tidak ada yang membaca "1.500" sebagai 1,5.
        return $kode === 'IDR' || $kode === 'MYR'
            ? $awalan.number_format((float) $nominal, $desimal, ',', '.')
            : $awalan.number_format((float) $nominal, $desimal, '.', ',');
    }

    /** Nominal sebuah tagihan, memakai mata uang yang tersimpan di barisnya. */
    public static function invoice(object $invoice, string $kolom = 'total'): string
    {
        return self::format($invoice->{$kolom} ?? 0, $invoice->currency ?? 'IDR');
    }

    /** Apakah kode mata uang dikenal panel admin. */
    public static function dikenal(string $kode): bool
    {
        return array_key_exists(strtoupper(trim($kode)), self::PILIHAN);
    }
}
