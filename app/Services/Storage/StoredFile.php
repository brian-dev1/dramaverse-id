<?php

namespace App\Services\Storage;

use App\Support\Bytes;
use App\Enums\StorageDriver;
use App\Models\StorageProvider;

/**
 * Hasil satu operasi tulis Storage Engine.
 *
 * Objek nilai, tidak bisa diubah. Isinya adalah SEGALA hal yang dibutuhkan
 * modul pemanggil untuk menemukan berkasnya lagi di kemudian hari — termasuk
 * provider mana yang menyimpannya.
 *
 * `providerId` adalah field yang paling mudah dianggap tidak penting dan
 * paling menyakitkan kalau hilang. Tanpa menyimpannya bersama object key,
 * berkas yang diunggah hari ini ke R2 tidak akan bisa ditemukan lagi setelah
 * provider default dipindah ke Wasabi besok: key-nya benar, tapi dicari di
 * bucket yang salah. Modul yang memakai engine ini WAJIB menyimpan
 * `provider_id` bersama `object_key`, bukan hanya key-nya.
 */
class StoredFile
{
    public function __construct(
        public readonly int $providerId,
        public readonly string $providerName,
        public readonly StorageDriver $driver,
        public readonly ?string $bucket,
        public readonly string $objectKey,
        public readonly string $fileName,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly ?string $extension,
        public readonly int $size,
        public readonly ?string $url = null,
        public readonly string $visibility = 'private',
    ) {
    }

    /**
     * Bangun dari sebuah provider dan hasil operasi.
     */
    public static function make(
        StorageProvider $provider,
        string $objectKey,
        string $originalName,
        string $mimeType,
        int $size,
        ?string $url = null,
        string $visibility = 'private',
    ): self {

        return new self(
            providerId:   (int) $provider->getKey(),
            providerName: (string) $provider->name,
            driver:       $provider->driver,
            bucket:       $provider->bucket,
            objectKey:    $objectKey,
            fileName:     ObjectKey::basenameOf($objectKey),
            originalName: $originalName,
            mimeType:     $mimeType,
            extension:    ObjectKey::extension(pathinfo($objectKey, PATHINFO_EXTENSION) ?: null),
            size:         $size,
            url:          $url,
            visibility:   $visibility,
        );
    }

    public function directory(): string
    {
        return ObjectKey::directoryOf($this->objectKey);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }


    /** Ukuran berkas dalam bentuk yang enak dibaca. */
    public function sizeForHumans(): string
    {
        return Bytes::forHumans($this->size);
    }


    /**
     * Bentuk array untuk disimpan ke kolom database.
     *
     * Kuncinya memakai snake_case supaya bisa langsung dipakai `fill()` atau
     * `create()` oleh modul yang menyimpannya.
     */
    public function toArray(): array
    {
        return [
            'provider_id'   => $this->providerId,
            'provider_name' => $this->providerName,
            'driver'        => $this->driver->value,
            'bucket'        => $this->bucket,
            'object_key'    => $this->objectKey,
            'file_name'     => $this->fileName,
            'original_name' => $this->originalName,
            'mime_type'     => $this->mimeType,
            'extension'     => $this->extension,
            'size'          => $this->size,
            'url'           => $this->url,
            'visibility'    => $this->visibility,
        ];
    }

    /**
     * Konteks untuk log. Sengaja terpisah dari toArray(): yang masuk log
     * hanya yang berguna saat menelusuri masalah, dan tidak ada satu pun
     * kredensial di antaranya.
     */
    public function logContext(): array
    {
        return [
            'provider_id' => $this->providerId,
            'driver'      => $this->driver->value,
            'bucket'      => $this->bucket,
            'object_key'  => $this->objectKey,
            'mime_type'   => $this->mimeType,
            'size'        => $this->size,
            'visibility'  => $this->visibility,
        ];
    }
}
