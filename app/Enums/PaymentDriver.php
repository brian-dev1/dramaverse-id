<?php

namespace App\Enums;

use App\Services\Payments\Drivers\ManualTransferGateway;
use App\Services\Payments\Drivers\MidtransGateway;
use App\Services\Payments\Drivers\TrakteerGateway;
use App\Services\Payments\Drivers\TripayGateway;
use App\Services\Payments\Drivers\XenditGateway;

/**
 * Daftar driver pembayaran yang dikenal aplikasi.
 *
 * Bentuknya sengaja dibuat sama dengan `StorageDriver` di Sprint 7.1, dengan
 * alasan yang sama persis: **provider tidak boleh dipatok di kode.** Yang ada
 * di sini hanyalah daftar teknologi yang bisa dipakai; provider yang benar-benar
 * aktif, kredensialnya, dan mana yang jadi default ada di tabel
 * `payment_providers` dan bisa diganti dari panel admin tanpa deploy.
 *
 * ## Kenapa `isImplemented()` ada
 *
 * Menambahkan case di sini tidak membuat driver-nya jadi bekerja. Beberapa
 * driver butuh kredensial dan endpoint yang hanya bisa diuji dengan akun
 * sungguhan, dan menyatakan siap tanpa pernah menjalankannya adalah bentuk
 * kebohongan yang paling mahal di sistem pembayaran.
 *
 * Driver yang belum selesai tetap terdaftar — supaya kerangkanya terlihat dan
 * penambahannya tidak perlu menyentuh Business Logic Membership sama sekali —
 * tetapi menolak dipakai dengan pesan yang menyebutkan apa yang kurang.
 * Sama seperti `StorageDriver` yang menyebut paket composer mana yang belum
 * terpasang.
 */
enum PaymentDriver: string
{
    /** Transfer bank manual, diverifikasi admin. Tidak butuh API mana pun. */
    case MANUAL = 'manual';

    case TRAKTEER = 'trakteer';

    case MIDTRANS = 'midtrans';

    case XENDIT = 'xendit';

    case TRIPAY = 'tripay';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL   => 'Transfer Manual',
            self::TRAKTEER => 'Trakteer',
            self::MIDTRANS => 'Midtrans',
            self::XENDIT   => 'Xendit',
            self::TRIPAY   => 'Tripay',
        };
    }

    /** Kelas yang menjalankannya. */
    public function gateway(): string
    {
        return match ($this) {
            self::MANUAL   => ManualTransferGateway::class,
            self::TRAKTEER => TrakteerGateway::class,
            self::MIDTRANS => MidtransGateway::class,
            self::XENDIT   => XenditGateway::class,
            self::TRIPAY   => TripayGateway::class,
        };
    }

    /**
     * Sudah bisa dipakai sungguhan.
     *
     * Yang false masih kerangka: alur callback dan tanda tangannya belum
     * pernah diuji dengan akun sungguhan.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::MANUAL, self::TRAKTEER => true,
            default => false,
        };
    }

    /**
     * Field kredensial yang wajib diisi.
     *
     * Dipakai validasi form admin, sehingga menambah driver baru tidak perlu
     * menyentuh controller mana pun.
     *
     * @return array<string,string> nama field => keterangan
     */
    public function requiredFields(): array
    {
        return match ($this) {

            self::MANUAL => [
                'bank_name'      => 'Nama bank',
                'account_number' => 'Nomor rekening',
                'account_name'   => 'Atas nama',
            ],

            self::TRAKTEER => [
                'webhook_token' => 'Webhook token dari dashboard Trakteer',
                'page_url'      => 'URL halaman Trakteer, misalnya https://trakteer.id/namaanda',
                'unit_price'    => 'Harga satu unit di Trakteer, dalam rupiah. Misalnya 5000',
                'unit_name'     => 'Nama unitnya, misalnya Cendol atau Kopi',
            ],

            self::MIDTRANS => [
                'server_key' => 'Server Key',
                'client_key' => 'Client Key',
            ],

            self::XENDIT => [
                'secret_key'      => 'Secret API Key',
                'callback_token'  => 'Callback Verification Token',
            ],

            self::TRIPAY => [
                'api_key'      => 'API Key',
                'private_key'  => 'Private Key',
                'merchant_code' => 'Merchant Code',
            ],
        };
    }

    /**
     * Apakah pembayarannya diverifikasi manusia, bukan callback.
     *
     * Menentukan apa yang ditampilkan ke pengguna setelah checkout, dan apakah
     * tombol Verifikasi Manual di panel admin masuk akal untuk transaksi itu.
     */
    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    /**
     * Pembayarannya bisa datang bertahap.
     *
     * Trakteer bekerja dengan satuan: pengguna mengirim sejumlah unit, dan
     * satu paket berharga beberapa kali harga satuannya. Lima unit sekarang
     * dan lima lagi nanti datang sebagai dua webhook terpisah.
     *
     * Untuk driver ini, callback yang nominalnya kurang dari total TIDAK
     * ditolak — ia dijumlahkan ke `invoices.paid_amount`, dan membership
     * aktif begitu jumlahnya cukup.
     *
     * Untuk gateway biasa, kurang bayar tetap ditolak: mereka menagih nominal
     * pasti, dan selisihnya berarti ada yang salah.
     */
    public function allowsPartial(): bool
    {
        return $this === self::TRAKTEER;
    }

    /**
     * Harga satu unit, untuk driver yang menjual per satuan.
     *
     * Null berarti driver ini tidak memakai satuan.
     */
    public function unitField(): ?string
    {
        return $this === self::TRAKTEER ? 'unit_price' : null;
    }

    /** Mendukung pengembalian dana lewat API. */
    public function supportsRefund(): bool
    {
        return match ($this) {
            self::MIDTRANS, self::XENDIT => true,
            default => false,
        };
    }

    /** @return array<string,string> untuk dropdown panel admin */
    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {
            $hasil[$case->value] = $case->label()
                .($case->isImplemented() ? '' : ' (kerangka, belum diuji)');
        }

        return $hasil;
    }
}
