<?php

namespace App\Enums;

use App\Services\Payments\Drivers\ManualTransferGateway;
use App\Services\Payments\Drivers\MidtransGateway;
use App\Services\Payments\Drivers\QrisGateway;
use App\Services\Payments\Drivers\TrakteerGateway;
use App\Services\Payments\Drivers\TripayGateway;
use App\Services\Payments\Drivers\XoftwarePayGateway;
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

    /**
     * QRIS statis, diverifikasi admin.
     *
     * Bedanya dengan `MANUAL` bukan cuma kosmetik. Transfer bank memberi
     * pengguna tiga potong teks yang harus disalin benar; QRIS memberinya satu
     * gambar yang dipindai. Karena itu gambarnya WAJIB ada sebelum provider
     * boleh diaktifkan — QRIS tanpa gambar adalah metode pembayaran yang tidak
     * bisa dijalankan sama sekali, bukan metode yang tampilannya kurang rapi.
     */
    case QRIS = 'qris';

    case TRAKTEER = 'trakteer';

    case MIDTRANS = 'midtrans';

    case XENDIT = 'xendit';

    case TRIPAY = 'tripay';

    /**
     * Xoftware Pay — agregator QRIS, e-wallet, dan Virtual Account.
     *
     * Satu baris provider mewakili SATU channel; `channel_code` yang
     * menentukan. Menerima QRIS dan VA sekaligus berarti dua baris provider
     * dengan `api_key` yang sama.
     *
     * ## Arti tiap kredensial
     *
     * - `base_url` — host API, misalnya `https://api.xoftwarepay.com`, tanpa
     *   garis miring di akhir. Wajib karena dokumentasi Xoftware Pay hanya
     *   menyebut path relatif dan tidak pernah menyebut host-nya.
     * - `merchant_id` — angka, ada di dashboard.
     * - `api_key` — dipakai autentikasi DAN menandatangani permintaan keluar
     *   (base64).
     * - `webhook_secret` — dipakai memverifikasi callback masuk (hex). BUKAN
     *   kunci yang sama dengan `api_key`, meski header pembawanya sama-sama
     *   `X-Signature`.
     * - `channel_code` — misalnya `QRIS`. Channel e-wallet ditolak driver;
     *   lihat `XoftwarePayGateway`.
     */
    case XOFTWAREPAY = 'xoftwarepay';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL   => 'Transfer Manual',
            self::QRIS     => 'QRIS',
            self::TRAKTEER => 'Trakteer',
            self::MIDTRANS => 'Midtrans',
            self::XENDIT   => 'Xendit',
            self::TRIPAY   => 'Tripay',
            self::XOFTWAREPAY => 'Xoftware Pay',
        };
    }

    /** Kelas yang menjalankannya. */
    public function gateway(): string
    {
        return match ($this) {
            self::MANUAL   => ManualTransferGateway::class,
            self::QRIS     => QrisGateway::class,
            self::TRAKTEER => TrakteerGateway::class,
            self::MIDTRANS => MidtransGateway::class,
            self::XENDIT   => XenditGateway::class,
            self::TRIPAY   => TripayGateway::class,
            self::XOFTWAREPAY => XoftwarePayGateway::class,
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
            self::MANUAL, self::QRIS, self::TRAKTEER, self::XOFTWAREPAY => true,
            default => false,
        };
    }

    /**
     * Driver ini memerlukan gambar QRIS yang diunggah admin.
     *
     * Dipisahkan dari `requiredFields()` karena gambarnya bukan kredensial:
     * ia berkas, bukan teks, dan tersimpan di kolomnya sendiri
     * (`payment_providers.qris_image_path`), bukan di `credentials` yang
     * terenkripsi. Yang memeriksanya `PaymentProvider::missingFields()`.
     */
    public function needsQrisImage(): bool
    {
        return $this === self::QRIS;
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

            // Hanya nama merchantnya. Nominal, tujuan, dan cara bayarnya
            // semua ada di dalam gambar QR — mengetiknya lagi sebagai field
            // hanya menambah tempat baru untuk salah ketik.
            self::QRIS => [
                'merchant_name' => 'Nama merchant yang tampil di aplikasi pembayar',
            ],

            self::TRAKTEER => [
                'webhook_token' => 'Webhook token dari dashboard Trakteer',
                'page_url'      => 'URL halaman Trakteer, misalnya https://trakteer.id/namaanda',
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

            /*
            | Dua kunci yang berbeda, dan keduanya wajib.
            |
            | `api_key` menandatangani permintaan KELUAR (base64), sementara
            | `webhook_secret` memverifikasi callback MASUK (hex). Namanya
            | mirip dan header pembawanya sama-sama `X-Signature`, tetapi
            | tertukar sedikit pun membuat callback yang sah ditolak dengan
            | pesan yang menunjuk ke arah yang salah.
            |
            | `base_url` ikut wajib karena dokumentasi Xoftware Pay hanya
            | menyebut path relatif dan tidak pernah menyebut host-nya.
            | Menanamkannya di kode berarti pindah ke sandbox butuh deploy.
            */
            /*
            | Label sengaja PENDEK.
            |
            | Nilai-nilai ini muncul di dua tempat dengan kebutuhan yang
            | berlawanan: sebagai label field di form admin, dan sebagai
            | daftar field yang kurang di `missingLabels()` — yang dicetak
            | `payment:providers` sebagai satu baris dipisah koma.
            |
            | Kalimat penjelasan yang berguna di form berubah jadi tembok
            | teks yang tidak terbaca di baris itu. Penjelasannya karena itu
            | ada di docblock case ini dan di kolom `instruction` provider,
            | bukan di sini.
            */
            self::XOFTWAREPAY => [
                'base_url'       => 'Base URL API',
                'merchant_id'    => 'Merchant ID',
                'api_key'        => 'API Key',
                'webhook_secret' => 'Webhook Secret (HMAC)',
                'channel_code'   => 'Kode channel',
            ],
        };
    }

    /**
     * Field kredensial yang BOLEH kosong.
     *
     * Dipisahkan dari `requiredFields()` karena keduanya menjawab pertanyaan
     * berbeda: yang wajib menentukan apakah provider bisa dipakai, yang
     * opsional hanya memperbaiki tampilan.
     *
     * `units` masuk ke sini, bukan ke yang wajib. Trakteer mengizinkan
     * kreator membuat beberapa unit dengan harga berbeda -- Cendol Rp 5.000,
     * Kopi Rp 2.000, dan seterusnya -- dan daftar ini hanya dipakai untuk
     * MENYARANKAN berapa unit yang perlu dikirim.
     *
     * Pencocokan pembayaran TIDAK bergantung padanya sama sekali: nominal
     * dibaca dari payload webhook, apa pun unit yang dipakai pendukung. Itu
     * disengaja -- daftar yang basi karena harga di Trakteer berubah tidak
     * boleh membuat pembayaran yang sah jadi ditolak.
     *
     * @return array<string,string>
     */
    public function optionalFields(): array
    {
        return match ($this) {

            self::TRAKTEER => [
                'units' => 'Daftar unit dan harganya, satu per baris. '
                    ."Contoh:\nCendol=5000\nKopi=2000\nBoba=10000\n"
                    .'Boleh dikosongkan; hanya dipakai menyarankan jumlah unit '
                    .'ke pengguna, tidak memengaruhi pencocokan pembayaran.',
            ],

            self::XOFTWAREPAY => [
                'fee_direction' => 'Penanggung biaya (merchant / user)',
            ],

            default => [],
        };
    }

    /**
     * Seluruh field kredensial, wajib dan opsional.
     *
     * Inilah yang dipakai formulir panel admin dan penyimpanannya — kalau
     * yang dipakai hanya `requiredFields()`, field opsional tidak akan pernah
     * bisa diisi.
     *
     * @return array<string,string>
     */
    public function credentialFields(): array
    {
        return $this->requiredFields() + $this->optionalFields();
    }

    /**
     * Apakah pembayarannya diverifikasi manusia, bukan callback.
     *
     * Menentukan apa yang ditampilkan ke pengguna setelah checkout, dan apakah
     * tombol Verifikasi Manual di panel admin masuk akal untuk transaksi itu.
     */
    public function isManual(): bool
    {
        return $this === self::MANUAL || $this === self::QRIS;
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

    /** Driver ini menjual per satuan, bukan per nominal. */
    public function usesUnits(): bool
    {
        return $this === self::TRAKTEER;
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
