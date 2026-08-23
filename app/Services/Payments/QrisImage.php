<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;
use App\Support\Concerns\LogsPaymentEvents;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengubah payload QRIS dinamis jadi gambar yang bisa dipindai.
 *
 * ## Kenapa ini perlu ada
 *
 * Ada dua jenis QRIS di aplikasi ini, dan keduanya sampai ke pengguna lewat
 * jalan yang berbeda:
 *
 * - **QRIS statis** — satu gambar yang diunggah admin sekali, dipakai untuk
 *   semua tagihan, nominalnya diketik sendiri oleh pembayar. Tersimpan di
 *   `payment_providers.qris_image_path`.
 * - **QRIS dinamis** — dibuat gateway per transaksi, nominalnya sudah
 *   terkunci di dalamnya. Yang dikembalikan gateway BUKAN gambar, melainkan
 *   string payload EMVCo (`00020101021226640017ID.CO...`).
 *
 * Sebelum kelas ini ada, `PremiumHandler` dan halaman invoice hanya mengenal
 * yang pertama. Akibatnya pengguna yang membayar lewat Xoftware Pay menerima
 * tagihan tanpa QR sama sekali dan tanpa tombol bayar — `checkout_url` juga
 * null untuk QRIS — jadi tidak ada satu pun cara menyelesaikan pembayaran.
 * Tidak ada galat yang muncul, karena secara teknis semuanya "berhasil".
 *
 * ## Kegagalan tidak boleh menghentikan pembayaran
 *
 * Setiap method di sini mengembalikan null bila gagal, TIDAK melempar. Itu
 * kebalikan dari aturan di lapisan gateway, dan disengaja: di sana kegagalan
 * berarti uang bisa salah tercatat, di sini kegagalan cuma berarti gambarnya
 * tidak muncul.
 *
 * Pemanggilnya sudah punya jalur cadangan berupa pesan teks berisi nomor
 * tagihan, dan menggagalkan seluruh checkout karena satu gambar tidak bisa
 * dirender akan mengubah masalah kecil jadi masalah besar. Sebabnya tetap
 * dicatat, jadi tidak ada yang disembunyikan.
 */
class QrisImage
{
    use LogsPaymentEvents;

    /**
     * Disk penyimpanan hasil render.
     *
     * `local` (storage/app/private), bukan `public`. Gambar ini memuat
     * nominal dan referensi satu tagihan milik satu orang — tidak ada
     * alasan ia bisa diambil siapa pun yang menebak nama berkasnya.
     *
     * Bot mengunggahnya sebagai berkas dari disk, dan halaman web
     * menyisipkannya sebagai data URI. Keduanya tidak memerlukan URL publik.
     */
    private const DISK = 'local';

    private const FOLDER = 'qris-dinamis';

    /** Ukuran satu modul QR dalam piksel. */
    private const SKALA = 8;

    /**
     * Nama field yang mungkin memuat payload QR.
     *
     * Xoftware Pay memakai `qris_text`. Yang lain disediakan supaya gateway
     * berikutnya yang mengembalikan payload QR tidak perlu menyentuh kelas
     * ini sama sekali — cukup pastikan namanya salah satu dari daftar ini.
     */
    private const FIELD = ['qris_text', 'qr_string', 'qris_content', 'qr_content', 'qr_url'];

    /**
     * Payload QRIS mentah dari jawaban gateway, bila ada.
     */
    public function payload(?PaymentTransaction $transaction): ?string
    {
        $raw = (array) ($transaction?->response_payload ?? []);

        foreach (self::FIELD as $field) {

            $nilai = $raw[$field] ?? null;

            if (is_string($nilai) && trim($nilai) !== '') {
                return trim($nilai);
            }
        }

        return null;
    }

    /** Transaksi ini membawa QRIS dinamis. */
    public function ada(?PaymentTransaction $transaction): bool
    {
        return $this->payload($transaction) !== null;
    }

    /**
     * Path absolut gambar PNG-nya, atau null.
     *
     * Hasil render disimpan dan dipakai ulang. Nama berkasnya diturunkan dari
     * isi payload, bukan dari id transaksi: payload yang sama selalu
     * menghasilkan gambar yang sama, dan pengguna yang menekan "lihat tagihan"
     * lima kali tidak perlu membuat lima berkas identik.
     */
    public function path(?PaymentTransaction $transaction): ?string
    {
        $payload = $this->payload($transaction);

        if ($payload === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        $relatif = self::FOLDER.'/'.sha1($payload).'.png';

        if ($disk->exists($relatif)) {

            $absolut = $disk->path($relatif);

            // Berkas kosong berarti render sebelumnya putus di tengah.
            // Dibuang supaya percobaan ini tidak mewarisi kegagalannya.
            if (is_file($absolut) && filesize($absolut) > 0) {
                return $absolut;
            }

            $disk->delete($relatif);
        }

        $png = $this->render($payload, $transaction);

        if ($png === null) {
            return null;
        }

        /*
        |----------------------------------------------------------------------
        | Kegagalan menulis WAJIB dicatat
        |----------------------------------------------------------------------
        |
        | Versi pertama kelas ini mengembalikan null begitu saja bila
        | penyimpanan gagal. Akibatnya nyata: folder `qris-dinamis` sempat
        | terbuat sebagai milik root dengan mode 700 karena dirender lewat
        | tinker, www-data tidak bisa memasukinya, dan bot mengirim tagihan
        | tanpa QR — tanpa satu pun baris log yang menyebut ada yang salah.
        |
        | Yang terlihat dari luar identik dengan "gateway tidak mengirim
        | payload QR", padahal payloadnya ada dan yang gagal cuma izin tulis.
        | Dua sebab yang sama sekali berbeda dengan gejala yang sama adalah
        | tepat keadaan yang membuat penelusuran berputar-putar.
        |
        */
        try {
            $tersimpan = $disk->put($relatif, $png);

        } catch (Throwable $e) {
            $tersimpan = false;
        }

        $absolut = $disk->path($relatif);

        if ($tersimpan === false || ! is_file($absolut)) {

            $folder = dirname($absolut);

            $this->log('error', 'qris.store_failed', [
                'reference'    => $transaction?->reference,
                'path'         => $relatif,
                'folder'       => $folder,
                'folder_ada'   => is_dir($folder),
                'bisa_tulis'   => is_writable($folder),
                'dijalankan_as' => function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                    : '?',
                'petunjuk'     => 'Periksa kepemilikan folder: '
                    .'chown -R www-data:www-data storage/app/private/qris-dinamis',
            ]);

            return null;
        }

        return $absolut;
    }

    /**
     * Gambar sebagai data URI, untuk disisipkan langsung ke HTML.
     *
     * Dipakai halaman invoice. Data URI dipilih daripada route yang melayani
     * berkasnya karena route seperti itu perlu pemeriksaan kepemilikan
     * tersendiri — satu tempat lagi yang bisa salah, untuk gambar yang
     * ukurannya cuma beberapa kilobyte.
     */
    public function dataUri(?PaymentTransaction $transaction): ?string
    {
        $path = $this->path($transaction);

        if ($path === null) {
            return null;
        }

        $isi = @file_get_contents($path);

        return $isi === false || $isi === ''
            ? null
            : 'data:image/png;base64,'.base64_encode($isi);
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    /**
     * Ubah payload jadi PNG.
     *
     * ## Kenapa dibungkus try/catch selebar ini
     *
     * Library QR adalah ketergantungan luar yang bisa hilang (belum
     * `composer install` setelah deploy), berganti API antar versi mayor,
     * atau gagal karena ekstensi `gd` tidak terpasang di server. Ketiganya
     * menghasilkan Error atau Exception yang berbeda-beda.
     *
     * Yang membedakannya tidak penting bagi pemanggil: semuanya berarti
     * "gambarnya tidak bisa dibuat", dan semuanya harus berakhir dengan
     * pengguna tetap menerima nomor tagihannya lewat pesan teks. Karena itu
     * `Throwable`, bukan daftar exception tertentu.
     */
    private function render(string $payload, ?PaymentTransaction $transaction): ?string
    {
        if (! class_exists(\chillerlan\QRCode\QRCode::class)) {

            $this->log('warning', 'qris.renderer_missing', [
                'reference' => $transaction?->reference,
                'petunjuk'  => 'Jalankan `composer require chillerlan/php-qrcode` lalu deploy ulang.',
            ]);

            return null;
        }

        try {
            $png = (new \chillerlan\QRCode\QRCode($this->options()))->render($payload);

        } catch (Throwable $e) {

            $this->log('error', 'qris.render_failed', [
                'reference' => $transaction?->reference,
                'sebab'     => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_string($png) || $png === '') {
            return null;
        }

        /*
        | Sebagian versi library mengembalikan data URI, sebagian PNG mentah.
        |
        | Pilihannya diatur `outputBase64`, yang nilai bawaannya pernah
        | berubah antar versi mayor. Alih-alih memaksakan satu versi, hasilnya
        | diperiksa apa adanya: yang berawalan `data:` dipotong prefiksnya dan
        | didekode. Ini membuat kelasnya tetap bekerja apa pun versi yang
        | terpasang, tanpa perlu mendeteksi versinya.
        */
        if (str_starts_with($png, 'data:')) {

            $koma = strpos($png, ',');

            $mentah = $koma === false ? false : base64_decode(substr($png, $koma + 1), true);

            return is_string($mentah) && $mentah !== '' ? $mentah : null;
        }

        return $png;
    }

    /**
     * Opsi render, disusun agar berlaku di beberapa versi library.
     *
     * v4 memilih format lewat `outputType`, v5 lewat `outputInterface` yang
     * menerima nama kelas. Keduanya diisi bila kelasnya memang ada, dan yang
     * tidak dikenal versi terpasang diabaikan — itu jauh lebih murah daripada
     * memaksa satu versi tertentu di `composer.json` lalu bertabrakan dengan
     * paket lain di kemudian hari.
     */
    private function options(): \chillerlan\QRCode\QROptions
    {
        $opsi = [
            'scale'         => self::SKALA,
            'outputBase64'  => false,
            'imageBase64'   => false,
            'quietzoneSize' => 4,
            'addQuietzone'  => true,
        ];

        // v5
        if (class_exists(\chillerlan\QRCode\Output\QRGdImagePNG::class)) {
            $opsi['outputInterface'] = \chillerlan\QRCode\Output\QRGdImagePNG::class;
        }

        // v4
        if (defined(\chillerlan\QRCode\QRCode::class.'::OUTPUT_IMAGE_PNG')) {
            $opsi['outputType'] = \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG;
        }

        return new \chillerlan\QRCode\QROptions($opsi);
    }
}
