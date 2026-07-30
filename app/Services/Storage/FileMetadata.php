<?php

namespace App\Services\Storage;

use Illuminate\Support\Carbon;

/**
 * Keterangan sebuah berkas yang sudah tersimpan.
 *
 * Sebagian field bisa `null`, dan itu bukan kegagalan. Tidak semua provider
 * melaporkan semua hal: `mimeType` tidak selalu tersimpan sebagai metadata
 * objek, dan `visibility` tidak dikenal oleh R2 maupun B2 yang tidak
 * mendukung ACL objek gaya S3. Memaksa nilai untuk field yang tidak
 * dilaporkan hanya akan menghasilkan tebakan yang terlihat seperti fakta.
 */
class FileMetadata
{
    public function __construct(
        public readonly int $providerId,
        public readonly string $objectKey,
        public readonly bool $exists,
        public readonly ?int $size = null,
        public readonly ?string $mimeType = null,
        public readonly ?Carbon $lastModified = null,
        public readonly ?string $visibility = null,
        public readonly ?string $url = null,
    ) {
    }

    public static function missing(int $providerId, string $objectKey): self
    {
        return new self(
            providerId: $providerId,
            objectKey: $objectKey,
            exists: false,
        );
    }

    public function sizeForHumans(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $size = (float) $this->size;

        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : number_format($size, 2)).' '.$units[$i];
    }

    public function toArray(): array
    {
        return [
            'provider_id'   => $this->providerId,
            'object_key'    => $this->objectKey,
            'exists'        => $this->exists,
            'size'          => $this->size,
            'mime_type'     => $this->mimeType,
            'last_modified' => $this->lastModified?->toIso8601String(),
            'visibility'    => $this->visibility,
            'url'           => $this->url,
        ];
    }
}
