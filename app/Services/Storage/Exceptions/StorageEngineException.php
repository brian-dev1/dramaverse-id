<?php

namespace App\Services\Storage\Exceptions;

use App\Enums\StorageCollection;
use App\Models\StorageProvider;
use RuntimeException;
use Throwable;

/**
 * Kegagalan operasi berkas di Storage Engine.
 *
 * Dipisahkan dari StorageProviderException, yang mengurusi konfigurasi
 * provider. Pemisahan ini ada gunanya bagi pemanggil: kegagalan konfigurasi
 * perlu perhatian operator (isi kredensial, pasang paket composer), sedangkan
 * kegagalan operasi berkas biasanya perlu ditampilkan ke pengguna yang sedang
 * mengunggah.
 *
 * Setiap pesan menyebut apa yang salah DAN apa langkah berikutnya. Pesan yang
 * hanya menyebut gejala membuat orang menebak.
 */
class StorageEngineException extends RuntimeException
{
    /*
    |--------------------------------------------------------------------------
    | Pemilihan provider
    |--------------------------------------------------------------------------
    */

    public static function noDefaultProvider(): self
    {
        return new self(
            'Mode Auto tidak bisa dipakai: belum ada storage provider yang '
            .'ditandai default dan aktif. Tandai satu provider sebagai default '
            .'di Storage Manager, atau sebut provider-nya secara eksplisit.'
        );
    }

    public static function providerNotFound(int|string $identifier): self
    {
        return new self(
            "Storage provider `{$identifier}` tidak ditemukan. Provider yang "
            .'sudah dihapus juga tidak bisa dipakai — pulihkan dulu bila memang '
            .'masih diperlukan.'
        );
    }

    public static function providerInactive(StorageProvider $provider): self
    {
        return new self(
            "Storage provider `{$provider->slug}` berstatus nonaktif, jadi "
            .'tidak menerima berkas baru. Aktifkan dulu di Storage Manager.'
        );
    }

    public static function driverUnavailable(StorageProvider $provider): self
    {
        return new self(
            "Adapter untuk `{$provider->driver->label()}` belum terpasang di "
            ."server, jadi provider `{$provider->slug}` tidak bisa dipakai. "
            .'Jalankan: composer require '.$provider->driver->composerPackage()
        );
    }

    /**
     * Field wajib kosong — termasuk `bucket` untuk provider awan.
     */
    public static function providerIncomplete(StorageProvider $provider): self
    {
        $fields = implode(', ', $provider->missingFields());

        return new self(
            "Storage provider `{$provider->slug}` belum lengkap, jadi tidak "
            ."bisa menerima berkas. Field yang masih kosong: {$fields}."
        );
    }

    public static function providerHasPlaceholders(StorageProvider $provider): self
    {
        $fields = implode(', ', array_keys($provider->placeholderFields()));

        return new self(
            "Storage provider `{$provider->slug}` masih memuat nilai contoh "
            ."pada: {$fields}. Ganti dengan nilai sungguhan dulu."
        );
    }

    /**
     * Koneksi belum pernah terbukti berhasil.
     *
     * Hanya dilempar bila `storage.engine.require_verified_connection`
     * dinyalakan. Bawaannya mati — lihat alasannya di config/storage.php.
     */
    public static function connectionNotVerified(StorageProvider $provider): self
    {
        return new self(
            "Storage provider `{$provider->slug}` belum pernah lulus Test "
            .'Connection, dan engine disetel menolak provider yang belum '
            .'terbukti. Jalankan Test Connection dari Storage Manager, atau: '
            ."php artisan storage:test {$provider->slug}"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Object key
    |--------------------------------------------------------------------------
    */

    public static function invalidDirectory(string $directory, string $sebab): self
    {
        return new self(
            "Direktori tujuan tidak sah ({$sebab}): \"{$directory}\"."
        );
    }

    public static function invalidFilename(string $filename, string $sebab): self
    {
        return new self(
            "Nama berkas tidak sah ({$sebab}): \"{$filename}\"."
        );
    }

    public static function keyTooLong(string $key, int $max): self
    {
        return new self(sprintf(
            'Object key terlalu panjang (%d karakter, maksimal %d). '
            .'Perpendek direktori atau nama berkasnya.',
            strlen($key),
            $max
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Isi berkas
    |--------------------------------------------------------------------------
    */

    public static function invalidUpload(string $sebab): self
    {
        return new self(
            "Berkas yang diunggah tidak bisa diproses: {$sebab}."
        );
    }

    public static function extensionNotAllowed(
        StorageCollection $collection,
        ?string $extension
    ): self {

        $daftar = implode(', ', $collection->extensions());

        $terbaca = $extension === null ? 'tanpa ekstensi' : ".{$extension}";

        return new self(sprintf(
            'Jenis berkas %s tidak diterima untuk %s. Yang diizinkan: %s.',
            $terbaca,
            $collection->label(),
            $daftar
        ));
    }

    /**
     * Ekstensi yang dieksekusi server, ditolak apa pun koleksinya.
     */
    public static function blockedExtension(string $extension): self
    {
        return new self(sprintf(
            'Berkas berekstensi .%s tidak boleh disimpan. Ekstensi yang '
            .'dieksekusi server selalu ditolak, karena penyimpanan lokal '
            .'tersaji lewat web dan berkas seperti itu bisa dijalankan. '
            .'Daftarnya ada di config/storage.php pada engine.blocked_extensions.',
            $extension
        ));
    }

    public static function tooLarge(
        StorageCollection $collection,
        int $sizeKb,
        int $maxKb
    ): self {

        return new self(sprintf(
            'Berkas berukuran %s KB melewati batas untuk %s, yaitu %s KB.',
            number_format($sizeKb),
            $collection->label(),
            number_format($maxKb)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Operasi
    |--------------------------------------------------------------------------
    */

    public static function notFound(StorageProvider $provider, string $key): self
    {
        return new self(
            "Berkas `{$key}` tidak ada di provider `{$provider->slug}`."
        );
    }

    /**
     * Operasi gagal di sisi penyimpanan.
     *
     * Pesan asli dari SDK disertakan apa adanya. Pesan itu sering tidak enak
     * dibaca, tapi merangkumnya menghilangkan satu-satunya petunjuk yang
     * kadang menentukan.
     */
    public static function operationFailed(
        string $operation,
        StorageProvider $provider,
        string $key,
        Throwable $previous
    ): self {

        return new self(
            sprintf(
                'Operasi %s gagal pada provider `%s` untuk `%s`: %s',
                $operation,
                $provider->slug,
                $key,
                $previous->getMessage() ?: $previous::class
            ),
            0,
            $previous
        );
    }

    public static function temporaryUrlUnsupported(StorageProvider $provider): self
    {
        return new self(
            "Provider `{$provider->slug}` ({$provider->driver->label()}) tidak "
            .'mendukung temporary URL. Untuk penyimpanan lokal, sajikan berkas '
            .'lewat route yang memeriksa izin, bukan lewat URL bertanda tangan.'
        );
    }
}
