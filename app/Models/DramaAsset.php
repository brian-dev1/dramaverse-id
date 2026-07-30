<?php

namespace App\Models;

use App\Enums\DramaAssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu berkas aset milik sebuah drama.
 *
 * Seperti EpisodeVideo, baris ini adalah satu-satunya jalan aplikasi
 * menjangkau berkasnya kembali: `object_key` saja tidak cukup, karena key yang
 * sama bisa ada di beberapa bucket dan provider default bisa berpindah kapan
 * saja. `storage_provider_id` selalu dibaca bersamanya.
 */
class DramaAsset extends Model
{
    protected $fillable = [
        'drama_id',
        'asset_type',
        'storage_provider_id',
        'uploaded_by',
        'disk',
        'bucket',
        'object_key',
        'directory',
        'original_filename',
        'stored_filename',
        'extension',
        'mime_type',
        'size',
        'checksum',
        'public_url',
        'sort_order',
        'uploaded_at',
    ];

    protected $casts = [
        'drama_id'            => 'integer',
        'storage_provider_id' => 'integer',
        'uploaded_by'         => 'integer',
        'asset_type'          => DramaAssetType::class,
        'size'                => 'integer',
        'sort_order'          => 'integer',
        'uploaded_at'         => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'storage_provider_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeOfType(Builder $query, DramaAssetType|string $type): Builder
    {
        return $query->where(
            'asset_type',
            $type instanceof DramaAssetType ? $type->value : $type
        );
    }

    public function scopeForDrama(Builder $query, int $dramaId): Builder
    {
        return $query->where('drama_id', $dramaId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Turunan
    |--------------------------------------------------------------------------
    */

    public function getSizeForHumansAttribute(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        $size = (float) $this->size;

        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : number_format($size, 2)).' '.$units[$i];
    }

    public function getChecksumShortAttribute(): string
    {
        return substr((string) $this->checksum, 0, 8);
    }

    /**
     * Bisa ditampilkan sebagai gambar di panel.
     *
     * Bergantung pada dua hal sekaligus: jenisnya memang gambar, DAN URL
     * publiknya berhasil disusun. Provider tanpa `public_url` yang diisi tidak
     * punya alamat yang bisa ditebak, dan memasang <img src=""> yang kosong
     * hanya menghasilkan ikon gambar rusak.
     */
    public function isPreviewable(): bool
    {
        return $this->asset_type->isImage() && filled($this->public_url);
    }

    /**
     * Berkas masih bisa dijangkau aplikasi.
     *
     * Provider yang sudah dihapus (soft delete) membuat relasinya kosong:
     * berkasnya masih ada di bucket, tetapi kredensial untuk menjangkaunya
     * sudah tidak tersedia.
     */
    public function isReachable(): bool
    {
        return $this->provider !== null && $this->provider->isUsable();
    }
}
