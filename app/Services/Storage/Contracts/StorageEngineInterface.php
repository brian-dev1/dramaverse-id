<?php

namespace App\Services\Storage\Contracts;

use App\Enums\StorageCollection;
use App\Models\StorageProvider;
use App\Services\Storage\FileMetadata;
use App\Services\Storage\StoredFile;
use Illuminate\Http\UploadedFile;

/**
 * Storage Engine — pusat seluruh operasi berkas di DramaVerse ID.
 *
 * Aturannya satu dan tidak ada pengecualian: **controller tidak boleh
 * menyentuh Storage.** Controller memanggil kontrak ini (atau service modul
 * yang memanggil kontrak ini), dan tidak pernah tahu provider mana yang
 * dipakai, apa driver-nya, atau di bucket mana berkasnya berakhir.
 *
 * Alasannya bukan kerapian. Selama pengetahuan tentang provider tersebar di
 * controller, memindahkan penyimpanan dari R2 ke Wasabi berarti menyunting
 * setiap tempat yang pernah mengunggah sesuatu — dan yang terlewat baru
 * ketahuan saat ada berkas yang tidak bisa ditemukan.
 *
 * ## Dua mode pemilihan provider
 *
 * - **Auto** — argumen provider dibiarkan `null`. Engine memakai provider
 *   default. Ini yang dipakai hampir semua modul.
 * - **Manual** — sebut id (int) atau slug (string) provider yang aktif.
 *   Dipakai saat berkas harus mendarat di tempat tertentu, misalnya video
 *   episode yang sengaja dipisahkan dari gambar.
 *
 * Load balancing dan failover BELUM ada di sini. `StorageManager::chain()`
 * sudah menyiapkan urutannya, tetapi engine ini selalu memakai satu provider
 * dan gagal terang-terangan bila provider itu bermasalah — bukan diam-diam
 * berpindah ke provider lain, yang akan menyebarkan berkas satu modul ke
 * beberapa bucket tanpa ada yang memutuskan begitu.
 *
 * ## Kewajiban pemanggil
 *
 * Setiap operasi pada berkas yang sudah ada menerima provider secara
 * EKSPLISIT, tidak ada mode Auto. Ini disengaja: berkas berada di satu
 * provider tertentu, dan mencarinya di provider default hanya benar selama
 * default belum pernah dipindah. Karena itu modul yang menyimpan berkas wajib
 * menyimpan `provider_id` bersama `object_key`.
 */
interface StorageEngineInterface
{
    /*
    |--------------------------------------------------------------------------
    | Tulis
    |--------------------------------------------------------------------------
    */

    /**
     * Unggah berkas ke sebuah koleksi.
     *
     * Jalur yang paling disarankan: direktori, visibility, ekstensi yang
     * diizinkan, dan batas ukuran semuanya diambil dari koleksinya, sehingga
     * tidak ada modul yang perlu mengarang nilai-nilai itu sendiri.
     *
     * @param  int|string|null  $provider  null = Auto (provider default)
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function upload(
        UploadedFile $file,
        StorageCollection $collection,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile;

    /**
     * Unggah ke direktori apa pun, tanpa aturan koleksi.
     *
     * Untuk kebutuhan yang tidak cocok dengan koleksi mana pun. Tidak ada
     * pembatasan ekstensi maupun ukuran di sini — pemanggil yang bertanggung
     * jawab memvalidasinya.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function uploadTo(
        UploadedFile $file,
        string $directory,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile;

    /**
     * Tulis isi yang dibuat program, bukan berkas yang diunggah.
     *
     * Diperlukan modul yang menghasilkan berkasnya sendiri — subtitle hasil
     * konversi, manifest, berkas ekspor. Tanpa method ini modul seperti itu
     * harus menulis berkas sementara lalu membungkusnya sebagai UploadedFile,
     * dan pada akhirnya akan memilih menyentuh Storage langsung.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function putContents(
        string $contents,
        string $directory,
        string $filename,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile;

    /**
     * Ganti berkas yang sudah ada dengan yang baru.
     *
     * Bawaannya menulis object key BARU lalu menghapus yang lama, bukan
     * menimpa key yang sama. Menimpa key yang sama membuat CDN dan peramban
     * tetap menyajikan berkas lama — gejala klasik "sudah saya ganti tapi
     * yang muncul masih poster lama". Untuk memaksa menimpa di tempat, kirim
     * `['keep_key' => true]`.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function replace(
        int|string $provider,
        string $objectKey,
        UploadedFile $file,
        array $options = []
    ): StoredFile;

    /*
    |--------------------------------------------------------------------------
    | Pindah dan salin
    |--------------------------------------------------------------------------
    */

    /**
     * Ganti nama berkas, tetap di direktori yang sama.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function rename(
        int|string $provider,
        string $objectKey,
        string $newFilename
    ): StoredFile;

    /**
     * Pindahkan berkas ke direktori lain di provider yang sama.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function move(
        int|string $provider,
        string $objectKey,
        string $newDirectory
    ): StoredFile;

    /**
     * Salin berkas di dalam provider yang sama.
     *
     * Penyalinan ANTAR provider tidak disediakan di sprint ini. Itu operasi
     * yang berbeda sifatnya — perlu aliran data, perlu tahan terhadap
     * kegagalan di tengah jalan, dan untuk berkas video bisa berjalan lama.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function copy(
        int|string $provider,
        string $objectKey,
        string $targetDirectory,
        ?string $targetFilename = null
    ): StoredFile;

    /*
    |--------------------------------------------------------------------------
    | Hapus
    |--------------------------------------------------------------------------
    */

    /**
     * Hapus berkas.
     *
     * Mengembalikan `false` bila berkasnya memang sudah tidak ada — itu bukan
     * kegagalan, dan tidak melempar exception. Penghapusan yang idempoten
     * penting untuk pembersihan: kode pembersih tidak boleh gagal hanya
     * karena pekerjaannya sudah dilakukan orang lain.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     *         bila penyimpanan menolak permintaannya.
     */
    public function delete(int|string $provider, string $objectKey): bool;

    /*
    |--------------------------------------------------------------------------
    | Baca
    |--------------------------------------------------------------------------
    */

    public function exists(int|string $provider, string $objectKey): bool;

    /**
     * Keterangan berkas: ukuran, mime, waktu ubah, visibility, URL.
     */
    public function metadata(int|string $provider, string $objectKey): FileMetadata;

    /**
     * URL publik permanen, atau `null` bila provider tidak bisa menyusunnya.
     *
     * `null` bukan galat. Provider tanpa `public_url` yang diisi memang tidak
     * punya alamat publik yang bisa ditebak engine.
     */
    public function url(int|string $provider, string $objectKey): ?string;

    /**
     * URL bertanda tangan yang kedaluwarsa.
     *
     * Inilah cara yang benar menyajikan berkas privat — video episode dan
     * subtitle. Penyimpanan lokal tidak mendukungnya; kirim `$strict = false`
     * untuk mendapat `null` alih-alih exception bila provider tidak mampu.
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function temporaryUrl(
        int|string $provider,
        string $objectKey,
        int $minutes = 60,
        bool $strict = true
    ): ?string;

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    */

    /**
     * Provider yang akan dipakai untuk argumen tertentu, sudah lolos validasi.
     *
     * Berguna bagi modul yang perlu tahu tujuannya sebelum benar-benar
     * mengunggah — misalnya untuk menampilkannya di form.
     *
     * @param  int|string|null  $provider  null = Auto (provider default)
     *
     * @throws \App\Services\Storage\Exceptions\StorageEngineException
     */
    public function resolveProvider(int|string|null $provider = null): StorageProvider;
}
