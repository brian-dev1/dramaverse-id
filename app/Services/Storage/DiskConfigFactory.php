<?php

namespace App\Services\Storage;

use App\Models\StorageProvider;
use App\Services\Storage\Exceptions\StorageProviderException;

/**
 * Menerjemahkan satu baris storage_providers menjadi array konfigurasi disk
 * yang dipahami Laravel.
 *
 * Kelas ini sengaja dipisah dari StorageManager dan tidak menyentuh apa pun
 * di luar dirinya: tidak query, tidak menulis berkas, tidak menyentuh
 * container. Dengan begitu ia bisa diuji tanpa database dan tanpa jaringan —
 * satu-satunya bagian dari sistem storage yang bisa diverifikasi murni.
 */
class DiskConfigFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(StorageProvider $provider): array
    {
        $this->assertUsable($provider);

        $config = match ($provider->driver->flysystemDriver()) {
            'local' => $this->localConfig($provider),
            's3'    => $this->s3Config($provider),
            'gcs'   => $this->gcsConfig($provider),
            'azure' => $this->azureConfig($provider),
            default => throw StorageProviderException::unsupportedDriver(
                $provider->driver->value
            ),
        };

        // Opsi khusus provider ditimpakan paling akhir supaya admin bisa
        // memaksa nilai tertentu tanpa menunggu kolom baru dibuat.
        return array_merge($config, $provider->options ?? []);
    }

    /**
     * Lengkap dan adapternya terpasang. Status aktif TIDAK diperiksa di sini:
     * Test Connection harus bisa dijalankan pada provider yang masih
     * nonaktif — justru itu gunanya.
     */
    public function assertUsable(StorageProvider $provider): void
    {
        if (! $provider->isConfigured()) {
            throw StorageProviderException::incomplete($provider);
        }

        if (! $provider->hasAdapterInstalled()) {
            throw StorageProviderException::adapterMissing($provider);
        }
    }

    /**
     * Penyimpanan lokal.
     *
     * `root` di sini adalah path absolut di server, bukan prefix bucket.
     * Kalau admin mengisinya relatif, path itu diselesaikan di bawah
     * storage/app supaya tidak pernah keluar dari direktori aplikasi.
     */
    protected function localConfig(StorageProvider $provider): array
    {
        $root = $provider->root
            ? $this->resolveLocalRoot($provider->root)
            : storage_path('app/'.$provider->slug);

        return [
            'driver'     => 'local',
            'root'       => $root,
            'url'        => $provider->normalizedPublicUrl(),
            'visibility' => $provider->visibility,

            // Kegagalan tulis harus melempar exception, bukan mengembalikan
            // false yang diam-diam terabaikan.
            'throw'      => true,
            'report'     => false,
        ];
    }

    protected function resolveLocalRoot(string $root): string
    {
        // Absolut gaya Unix (/srv/media) atau gaya Windows (D:\media).
        $isAbsolute = preg_match('#^(/|[A-Za-z]:[/\\\\])#', $root) === 1;

        if ($isAbsolute) {
            return $root;
        }

        $relative = preg_replace('#^[/\\\\]+#', '', $root);

        return storage_path('app/'.$relative);
    }

    /**
     * Semua provider berprotokol S3: Amazon S3, R2, B2, Wasabi, Spaces, MinIO.
     */
    protected function s3Config(StorageProvider $provider): array
    {
        $config = [
            'driver'                  => 's3',
            'key'                     => $provider->access_key,
            'secret'                  => $provider->secret_key,
            'region'                  => $provider->effectiveRegion(),
            'bucket'                  => $provider->bucket,
            'endpoint'                => $provider->normalizedEndpoint(),
            'url'                     => $provider->normalizedPublicUrl(),
            'use_path_style_endpoint' => $provider->effectivePathStyle(),
            'visibility'              => $provider->visibility,
            'throw'                   => true,
            'report'                  => false,
        ];

        // Prefix di dalam bucket. Hanya dikirim kalau memang diisi — array
        // dengan 'root' => null membuat adapter menganggapnya prefix kosong
        // bernama "null" pada beberapa versi.
        if (filled($provider->root)) {
            $config['root'] = trim($provider->root, '/');
        }

        return $config;

        // Catatan sengaja: TIDAK ada penyesuaian khusus per provider di sini.
        //
        // R2 dan B2 memang menyimpang dari S3 Amazon — keduanya tidak mengenal
        // ACL objek, dan R2 pernah bermasalah dengan flexible checksums di AWS
        // SDK PHP. Godaannya adalah menambahkan kunci konfigurasi untuk itu di
        // sini. Saya tidak melakukannya karena nama kunci yang benar berbeda
        // antar versi SDK, dan saya tidak punya PHP untuk mengujinya. Kunci
        // yang salah nama tidak diabaikan diam-diam oleh S3Client — ia bisa
        // menggagalkan pembuatan klien, sehingga provider yang tadinya jalan
        // justru mati.
        //
        // Kalau sebuah provider butuh penyesuaian, isikan lewat kolom `options`
        // (JSON) pada barisnya. Nilai di kolom itu ditimpakan paling akhir oleh
        // make(), sehingga bisa dicoba dan dibatalkan tanpa deploy.
    }

    /**
     * Google Cloud Storage.
     *
     * BELUM DIUJI. Autentikasi GCS berbasis berkas kredensial JSON, bukan
     * pasangan key/secret, sehingga jalurnya berbeda dari semua provider lain
     * di sistem ini. Path berkas kredensial harus diisi lewat kolom `options`
     * (`key_file_path`) supaya kunci layanan tidak tersimpan di kolom database.
     *
     * Konfigurasi di bawah hanya kerangka minimal. Selesaikan bersama paket
     * league/flysystem-google-cloud-storage saat GCS benar-benar dipakai.
     */
    protected function gcsConfig(StorageProvider $provider): array
    {
        $config = [
            'driver'     => 'gcs',
            'bucket'     => $provider->bucket,
            'visibility' => $provider->visibility,
            'throw'      => true,
        ];

        if (filled($provider->root)) {
            $config['path_prefix'] = trim($provider->root, '/');
        }

        return $config;
    }

    /**
     * Azure Blob Storage. `bucket` dipakai sebagai nama container.
     *
     * BELUM DIUJI, dengan alasan yang sama seperti GCS: connection string
     * Azure harus diisi lewat kolom `options`.
     */
    protected function azureConfig(StorageProvider $provider): array
    {
        $config = [
            'driver'     => 'azure',
            'container'  => $provider->bucket,
            'url'        => $provider->normalizedPublicUrl(),
            'visibility' => $provider->visibility,
            'throw'      => true,
        ];

        if (filled($provider->root)) {
            $config['prefix'] = trim($provider->root, '/');
        }

        return $config;
    }

    /**
     * Konfigurasi dengan kredensial disamarkan, untuk log dan tampilan debug.
     */
    public function makeRedacted(StorageProvider $provider): array
    {
        $config = $this->make($provider);

        foreach (['key', 'secret'] as $sensitive) {
            if (isset($config[$sensitive])) {
                $config[$sensitive] = '[disamarkan]';
            }
        }

        return $config;
    }
}
