<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Penyimpanan bukti bayar: menyalin dari Telegram, dan membacanya kembali.
 *
 * ## Kenapa berkasnya TIDAK lagi di disk `public`
 *
 * Versi sebelumnya menyimpan bukti di `storage/app/public` dan menampilkannya
 * lewat `asset('storage/...')`. Dua hal rusak karenanya, dan keduanya baru
 * terasa di server sungguhan:
 *
 * 1. **Tidak terlihat.** URL itu hanya bekerja bila symlink `public/storage`
 *    ada dan folder tujuannya bisa ditulis php-fpm. Kalau salah satunya tidak
 *    terpenuhi — symlink belum dibuat, atau `storage/app/public` masih milik
 *    root sesudah deploy — hasilnya bukan galat, melainkan gambar rusak di
 *    panel. `Storage::put()` mengembalikan `false` dan nilainya dulu diabaikan,
 *    jadi database tetap mencatat path berkas yang tidak pernah ada.
 *
 * 2. **Bocor.** Bukti bayar adalah tangkapan layar mutasi rekening: nama
 *    pemilik, nomor rekening, saldo. Di disk `public` ia bisa dibuka siapa pun
 *    yang tahu URL-nya, tanpa login. Nama berkasnya memang UUID, tetapi
 *    "sulit ditebak" bukan kendali akses — dan URL bocor lewat riwayat
 *    peramban, log proksi, dan tombol bagikan.
 *
 * Jadi berkasnya pindah ke disk privat, dan panel membacanya lewat route
 * admin yang memeriksa izin. Yang hilang cuma kemudahan menyajikan berkas
 * statis; yang didapat adalah bukti yang selalu bisa dibuka admin dan tidak
 * bisa dibuka orang lain.
 *
 * ## `file_id` sebagai jaring pengaman
 *
 * Telegram menyimpan fotonya sendiri dan memberi kita `file_id`. Itu dicatat
 * berdampingan dengan berkas kita, dan dipakai menambal sendiri: kalau salinan
 * lokal hilang atau memang tidak pernah jadi, `path()` menariknya ulang dari
 * Telegram saat admin membukanya, lalu menyimpannya lagi.
 *
 * Artinya bukti yang gagal diunduh saat masuk tidak berarti bukti yang hilang.
 * Pengguna tidak perlu disuruh mengirim ulang sesuatu yang sebenarnya sudah
 * sampai.
 */
class PaymentProofStore
{
    /**
     * Disk tempat bukti disimpan sekarang.
     *
     * `local` berakar di `storage/app/private` — tidak ada di bawah
     * `public/`, jadi tidak ada URL yang bisa menjangkaunya langsung.
     */
    public const DISK = 'local';

    /**
     * Disk lama, masih dibaca tapi tidak ditulis lagi.
     *
     * Bukti yang masuk sebelum perubahan ini ada di sini. Memindahkannya lewat
     * migrasi akan menyentuh berkas yang mungkin sudah tidak ada; membacanya
     * dari dua tempat jauh lebih murah dan tidak bisa gagal.
     */
    private const DISK_LAMA = 'public';

    /** Batas ukuran yang mau diunduh, dalam byte. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string,string> Ekstensi yang diterima beserta mime-nya. */
    private const MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Menyimpan
    |--------------------------------------------------------------------------
    */

    /**
     * Salin berkas dari Telegram ke disk kita.
     *
     * Mengembalikan path relatif, atau null bila gagal. Kegagalannya TIDAK
     * dilempar: `file_id`-nya tetap dicatat pemanggil, jadi bukti masih bisa
     * ditarik belakangan lewat `path()` — dan membatalkan seluruh penerimaan
     * bukti karena satu unduhan gagal hanya memindahkan masalahnya ke pengguna.
     */
    public function simpan(string $fileId, string $invoiceNumber): ?string
    {
        try {
            $berkas = $this->telegram->getFile($fileId);

            $filePath = (string) $berkas->get('file_path', '');

            if ($filePath === '') {

                Log::warning('payment.proof.no_file_path', ['invoice' => $invoiceNumber]);

                return null;
            }

            if ((int) $berkas->get('file_size', 0) > self::MAX_BYTES) {

                Log::warning('payment.proof.too_large', [
                    'invoice' => $invoiceNumber,
                    'bytes'   => $berkas->get('file_size'),
                ]);

                return null;
            }

            $isi = $this->isiBerkas($filePath, $invoiceNumber);

            if ($isi === null) {
                return null;
            }

            // Ekstensi diambil dari path Telegram, bukan dari nama yang
            // dikirim klien: yang pertama sudah ditentukan server Telegram,
            // yang kedua datang dari perangkat pengguna.
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg');

            $ext = isset(self::MIME[$ext]) ? $ext : 'jpg';

            $tujuan = 'payment/proof/'.now()->format('Ym').'/'.Str::uuid()->toString().'.'.$ext;

            /*
            |------------------------------------------------------------------
            | Hasil tulisnya DIPERIKSA
            |------------------------------------------------------------------
            |
            | Disk `local` dikonfigurasi dengan `throw => false`, jadi tulisan
            | yang ditolak sistem berkas mengembalikan `false` tanpa melempar
            | apa pun. Nilai itu dulu diabaikan, dan itulah kenapa panel bisa
            | menampilkan bingkai gambar kosong: barisnya mencatat path berkas
            | yang tidak pernah selesai ditulis.
            |
            | Izin folder adalah penyebab yang paling sering, dan yang paling
            | mudah luput — `chown -R www-data` di deploy.sh hanya menyentuh
            | folder yang sudah ada saat itu.
            |
            */

            if (Storage::disk(self::DISK)->put($tujuan, $isi) === false) {

                Log::error('payment.proof.write_failed', [
                    'invoice' => $invoiceNumber,
                    'disk'    => self::DISK,
                    'path'    => $tujuan,
                    'petunjuk' => 'Periksa izin tulis storage/app/private.',
                ]);

                return null;
            }

            return $tujuan;

        } catch (Throwable $e) {

            Log::warning('payment.proof.download_failed', [
                'invoice' => $invoiceNumber,
                'sebab'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Isi berkas dari Telegram — dari disk bila bisa, kalau tidak lewat HTTP.
     *
     * ## Kenapa ada dua jalan
     *
     * `file_path` yang dikembalikan `getFile` bentuknya berbeda tergantung
     * server mana yang menjawab, dan perbedaannya tidak diumumkan di mana pun
     * selain pada nilainya sendiri:
     *
     * - **api.telegram.org** menjawab dengan potongan relatif seperti
     *   `photos/file_5.jpg`, yang harus digabung jadi URL unduh.
     *
     * - **Local Bot API Server** yang dijalankan dengan `--local` menjawab
     *   dengan path ABSOLUT di mesin itu sendiri, misalnya
     *   `/var/lib/telegram-bot-api/<token>/photos/file_5.jpg`. Berkasnya sudah
     *   ada di disk kita dan tidak disajikan lewat HTTP sama sekali.
     *
     * Versi sebelumnya selalu menempuh jalan pertama. Di server yang memakai
     * Local Bot API Server — dan proyek ini memakainya untuk video besar —
     * hasilnya adalah URL yang menempelkan path absolut ke belakang alamat
     * unduh, dan Telegram menjawabnya 404. Itulah 404 beruntun di log yang
     * membuat bukti bayar tidak pernah tersimpan sekali pun.
     *
     * Membaca disk dicoba lebih dulu karena ia jawaban yang pasti benar ketika
     * berlaku: kalau path absolutnya memang ada dan terbaca, tidak ada alasan
     * memutarnya lewat jaringan.
     */
    private function isiBerkas(string $filePath, string $invoiceNumber): ?string
    {
        /*
        |----------------------------------------------------------------------
        | Jalan 1 — berkasnya sudah ada di mesin ini
        |----------------------------------------------------------------------
        |
        | `is_file()` sekaligus menjadi penjagaannya. Nilai ini datang dari
        | Bot API server kita sendiri, bukan dari pengguna, tapi ia tetap
        | dipakai sebagai path — jadi yang diterima hanya berkas biasa yang
        | benar-benar ada dan benar-benar terbaca. Symlink dan direktori
        | gugur dengan sendirinya.
        |
        */

        $lokal = $this->telegram->localPath($filePath);

        if ($lokal !== null) {

            if (filesize($lokal) > self::MAX_BYTES) {

                Log::warning('payment.proof.too_large', [
                    'invoice' => $invoiceNumber,
                    'bytes'   => filesize($lokal),
                ]);

                return null;
            }

            $isi = @file_get_contents($lokal);

            if ($isi !== false) {
                return $isi;
            }

            Log::warning('payment.proof.local_read_failed', [
                'invoice'   => $invoiceNumber,
                'file_path' => $lokal,
                'petunjuk'  => 'Berkas ada tapi tidak terbaca. Periksa izin baca '
                    .'folder Local Bot API Server untuk user www-data.',
            ]);

            return null;
        }

        /*
        |----------------------------------------------------------------------
        | Jalan 2 — unduh lewat HTTP
        |----------------------------------------------------------------------
        */

        $respon = Http::timeout(30)->get($this->telegram->downloadUrl($filePath));

        if ($respon->successful()) {
            return $respon->body();
        }

        /*
        | URL unduhnya memuat token bot dan karena itu tidak boleh masuk log.
        | `file_path`-nya boleh, dan justru itu yang paling menjelaskan: path
        | absolut di sini berarti Bot API server lokal berjalan dalam mode
        | `--local` sementara berkasnya tidak terbaca oleh proses PHP —
        | biasanya soal izin folder, bukan soal Telegram.
        */

        $lokalDipakai = filled(config('telegram.api_dir'))
            || ! str_contains((string) config('telegram.api_url'), 'api.telegram.org');

        Log::warning('payment.proof.download_rejected', [
            'invoice'   => $invoiceNumber,
            'status'    => $respon->status(),
            'file_path' => $filePath,
            'petunjuk'  => $lokalDipakai
                ? 'Bot API server lokal dipakai tapi berkasnya tidak terbaca dari '
                    .'disk. Isi TELEGRAM_API_DIR dengan argumen --dir server itu, '
                    .'dan pastikan www-data boleh membacanya.'
                : 'Periksa TELEGRAM_API_URL dan TELEGRAM_BOT_TOKEN.',
        ]);

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Membaca
    |--------------------------------------------------------------------------
    */

    /**
     * Berkas bukti yang benar-benar ada, ditambal sendiri bila perlu.
     *
     * Urutannya: berkas di disk baru, lalu di disk lama, lalu unduh ulang dari
     * Telegram. Yang terakhir menyimpan hasilnya kembali ke barisnya, sehingga
     * penambalan hanya terjadi sekali per bukti.
     *
     * @return array{disk:string,path:string,mime:string}|null
     */
    public function berkas(PaymentTransaction $tx): ?array
    {
        foreach ([self::DISK, self::DISK_LAMA] as $disk) {

            $path = (string) $tx->proof_path;

            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                return ['disk' => $disk, 'path' => $path, 'mime' => $this->mime($path)];
            }
        }

        $fileId = (string) $tx->proof_file_id;

        if ($fileId === '') {
            return null;
        }

        $path = $this->simpan($fileId, $tx->invoice?->number ?? 'tx-'.$tx->id);

        if ($path === null) {
            return null;
        }

        $tx->forceFill(['proof_path' => $path])->save();

        Log::info('payment.proof.repaired', [
            'transaction' => $tx->id,
            'path'        => $path,
        ]);

        return ['disk' => self::DISK, 'path' => $path, 'mime' => $this->mime($path)];
    }

    /**
     * Buang salinan lokalnya.
     *
     * Dipakai saat bukti ditolak. Sebelum ini barisnya dikosongkan tetapi
     * berkasnya ditinggal — tangkapan layar mutasi rekening yang tidak lagi
     * dirujuk siapa pun, dan karena tidak dirujuk, tidak pernah terhapus.
     */
    public function hapus(PaymentTransaction $tx): void
    {
        $path = (string) $tx->proof_path;

        if ($path === '') {
            return;
        }

        foreach ([self::DISK, self::DISK_LAMA] as $disk) {

            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }

            } catch (Throwable $e) {

                // Berkas yang gagal dihapus tidak boleh membatalkan penolakan
                // bukti. Barisnya tetap dikosongkan pemanggil.
                Log::warning('payment.proof.delete_failed', [
                    'transaction' => $tx->id,
                    'disk'        => $disk,
                    'sebab'       => $e->getMessage(),
                ]);
            }
        }
    }

    /** Mime dari ekstensi; jpeg bila tidak dikenali. */
    private function mime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? 'image/jpeg';
    }
}
