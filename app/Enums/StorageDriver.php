<?php

namespace App\Enums;

/**
 * Daftar storage provider yang dikenali sistem.
 *
 * Nilai enum ini adalah identitas provider di mata DramaVerse, BUKAN nama
 * driver Flysystem. Enam dari sembilan provider di bawah sama-sama memakai
 * protokol S3, jadi driver Flysystem-nya sama-sama `s3` — yang membedakan
 * hanya endpoint, region, dan gaya penulisan path. Pemisahan ini dibuat
 * supaya admin memilih "Cloudflare R2", bukan "s3 dengan endpoint aneh",
 * dan supaya validasi field bisa berbeda per provider.
 */
enum StorageDriver: string
{
    case LOCAL = 'local';

    case S3 = 's3';

    case R2 = 'r2';

    case B2 = 'b2';

    case KILAT = 'kilat';

    case WASABI = 'wasabi';

    case SPACES = 'spaces';

    case MINIO = 'minio';

    case GCS = 'gcs';

    case AZURE = 'azure';

    /**
     * Nama yang ditampilkan ke admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOCAL  => 'Penyimpanan Lokal',
            self::S3     => 'Amazon S3',
            self::R2     => 'Cloudflare R2',
            self::B2     => 'Backblaze B2',
            self::KILAT  => 'Kilat Storage',
            self::WASABI => 'Wasabi',
            self::SPACES => 'DigitalOcean Spaces',
            self::MINIO  => 'MinIO',
            self::GCS    => 'Google Cloud Storage',
            self::AZURE  => 'Azure Blob Storage',
        };
    }

    /**
     * Driver Flysystem yang sebenarnya dipakai Laravel.
     */
    public function flysystemDriver(): string
    {
        return match ($this) {
            self::LOCAL => 'local',
            self::GCS   => 'gcs',
            self::AZURE => 'azure',
            default     => 's3',
        };
    }

    /**
     * Provider yang bicara protokol S3.
     */
    public function isS3Compatible(): bool
    {
        return $this->flysystemDriver() === 's3';
    }

    /**
     * Paket composer yang harus terpasang agar driver ini bisa dipakai.
     *
     * Laravel 12 tidak membawa adapter cloud apa pun secara bawaan. Tanpa
     * paket ini, membangun disk akan gagal saat dieksekusi — bukan saat
     * pemeriksaan statis. Karena itu nama paketnya disimpan di sini dan
     * diperiksa sebelum disk dibangun.
     */
    public function composerPackage(): ?string
    {
        return match ($this) {
            self::LOCAL => null,
            self::GCS   => 'league/flysystem-google-cloud-storage',
            self::AZURE => 'league/flysystem-azure-blob-storage',
            default     => 'league/flysystem-aws-s3-v3',
        };
    }

    /**
     * Kelas adapter yang harus ada. Dipakai untuk memeriksa apakah paket
     * composer di atas benar-benar terpasang.
     */
    public function adapterClass(): ?string
    {
        return match ($this) {
            self::LOCAL => null,
            self::GCS   => 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter',
            self::AZURE => 'League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter',
            default     => 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter',
        };
    }

    /**
     * Field yang wajib diisi agar provider bisa diaktifkan.
     *
     * Dipakai baik oleh validasi form admin nanti maupun oleh
     * DiskConfigFactory sebelum disk dibangun.
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::LOCAL => [],

            // R2 memakai region tetap `auto`, jadi region tidak diwajibkan.
            self::R2 => ['bucket', 'endpoint', 'access_key', 'secret_key'],

            // Kilat Storage menggunakan endpoint S3-compatible khusus.
            // Region diberi default `us-east-1` dan tidak perlu diisi admin.
            self::KILAT => [
                'bucket', 'endpoint', 'access_key', 'secret_key',
            ],

            // MinIO biasanya jalan di jaringan sendiri: endpoint wajib.
            self::MINIO => ['bucket', 'endpoint', 'access_key', 'secret_key'],

            self::B2, self::WASABI, self::SPACES => [
                'bucket', 'endpoint', 'region', 'access_key', 'secret_key',
            ],

            // S3 asli menurunkan endpoint dari region, jadi endpoint opsional.
            self::S3 => ['bucket', 'region', 'access_key', 'secret_key'],

            self::GCS => ['bucket'],

            self::AZURE => ['bucket'],
        };
    }

    /**
     * Region bawaan bila admin tidak mengisinya.
     */
    public function defaultRegion(): ?string
    {
        return match ($this) {
            self::R2    => 'auto',
            self::KILAT  => 'us-east-1',
            self::MINIO => 'us-east-1',
            default     => null,
        };
    }

    /**
     * Provider yang mengharuskan bucket ditulis di path, bukan sebagai
     * subdomain.
     *
     * R2 hanya melayani gaya path pada domain `*.r2.cloudflarestorage.com`.
     * Tanpa ini, SDK menyusun `bucket.account_id.r2.cloudflarestorage.com` —
     * host yang tidak pernah ada, sehingga yang muncul adalah kegagalan
     * handshake TLS. Pesan galatnya menyesatkan: kelihatan seperti masalah
     * sertifikat atau jaringan, padahal salah bentuk alamat.
     *
     * MinIO dan S3 gateway swakelola lain juga umumnya tidak memetakan
     * bucket ke subdomain.
     *
     * Kilat Storage menggunakan endpoint S3-compatible dan kita pakai
     * path-style untuk menjaga request tetap menuju endpoint S3 Kilat.
     *
     * B2, Wasabi, dan Spaces mendukung kedua gaya, jadi tidak dipaksa di
     * sini — admin tetap bisa menyalakannya sendiri lewat `use_path_style`.
     */
    public function prefersPathStyle(): bool
    {
        return match ($this) {
            self::R2,
            self::MINIO,
            self::KILAT => true,

            default => false,
        };
    }

    /**
     * Provider yang cocok dipakai sebagai tujuan berkas besar (video).
     * Belum dipakai di sprint ini — disiapkan untuk router upload nanti.
     */
    public function supportsLargeFiles(): bool
    {
        return $this !== self::LOCAL;
    }

    /**
     * Semua nilai enum sebagai array datar, untuk aturan validasi `in:`.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Pasangan nilai => label, untuk elemen <select> di panel admin.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}