<?php

namespace App\Models;

use App\Enums\DramaAssetType;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pekerjaan unggah di antrean.
 *
 * Barisnya dibuat saat berkas selesai diterima dari peramban, dan tetap ada
 * setelah pekerjaannya beres — inilah riwayat yang dibaca panel. Yang
 * mengubah statusnya hanya `UploadQueueService`; model ini sengaja tidak
 * menyediakan satu pun method yang menulis, supaya tidak ada jalur kedua yang
 * bisa memindahkan status tanpa mencatat log dan tanpa mengunci baris.
 */
class UploadJob extends Model
{
    protected $fillable = [
        'uuid',
        'batch_uuid',
        'type',
        'episode_id',
        'drama_id',
        'requested_provider_id',
        'storage_mode',
        'asset_type',
        'status',
        'original_filename',
        'extension',
        'mime_type',
        'size',
        'staged_path',
        'attempts',
        'max_attempts',
        'queue_connection',
        'queue_name',
        'episode_video_id',
        'drama_asset_id',
        'error_class',
        'error_message',
        'duration_ms',
        'created_by',
        'queued_at',
        'started_at',
        'finished_at',
        'cancelled_at',
    ];

    protected $casts = [
        'episode_id'            => 'integer',
        'drama_id'              => 'integer',
        'requested_provider_id' => 'integer',
        'episode_video_id'      => 'integer',
        'drama_asset_id'        => 'integer',
        'created_by'            => 'integer',
        'size'                  => 'integer',
        'attempts'              => 'integer',
        'max_attempts'          => 'integer',
        'duration_ms'           => 'integer',
        'status'                => UploadStatus::class,
        'queued_at'             => 'datetime',
        'started_at'            => 'datetime',
        'finished_at'           => 'datetime',
        'cancelled_at'          => 'datetime',
    ];

    /** Jenis unggahan yang sudah diimplementasikan. */
    public const TYPE_EPISODE_VIDEO = 'episode_video';

    /** Aset drama lewat antrean — ditambahkan Sprint 7.9 (Batch Upload). */
    public const TYPE_DRAMA_ASSET = 'drama_asset';

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function requestedProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'requested_provider_id');
    }

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(EpisodeVideo::class, 'episode_video_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DramaAsset::class, 'drama_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UploadJobLog::class)->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Query
    |--------------------------------------------------------------------------
    */

    public function scopeStatus(Builder $query, UploadStatus|string $status): Builder
    {
        return $query->where(
            'status',
            $status instanceof UploadStatus ? $status->value : $status
        );
    }

    /** Yang belum selesai: masih menunggu atau sedang dikerjakan. */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [
            UploadStatus::PENDING->value,
            UploadStatus::PROCESSING->value,
        ]);
    }

    /** Seluruh pekerjaan dalam satu batch, urut sesuai pengirimannya. */
    public function scopeBatch(Builder $query, string $batchUuid): Builder
    {
        return $query->where('batch_uuid', $batchUuid)->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Turunan
    |--------------------------------------------------------------------------
    */

    /**
     * Path absolut berkas staging, atau null bila memang tidak ada.
     *
     * Disimpan relatif di database supaya baris tetap benar ketika folder
     * project dipindah — path absolut yang dipatok akan menunjuk ke tempat
     * yang tidak ada lagi.
     */
    public function stagedFullPath(): ?string
    {
        return $this->staged_path ? storage_path($this->staged_path) : null;
    }

    public function hasStagedFile(): bool
    {
        $path = $this->stagedFullPath();

        return $path !== null && is_file($path);
    }

    /**
     * Retry hanya masuk akal bila berkasnya masih ada.
     *
     * Status `FAILED` saja tidak cukup: kalau berkas staging sudah dibersihkan
     * (mis. oleh `upload:prune`), tombol Retry akan mengantrekan pekerjaan
     * yang pasti gagal lagi dengan pesan berbeda dan lebih membingungkan.
     */
    public function isRetryable(): bool
    {
        return $this->status->canRetry() && $this->hasStagedFile();
    }

    public function isCancellable(): bool
    {
        return $this->status->canCancel();
    }

    public function getSizeForHumansAttribute(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $size = (float) $this->size;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%s %s', round($size, $i > 1 ? 2 : 0), $units[$i]);
    }

    /**
     * Tujuan pekerjaan, untuk kolom di tabel panel.
     *
     * Bentuknya berbeda per jenis: video menyebut episode, aset menyebut
     * drama dan jenis asetnya. Dipilih di sini dan bukan di Blade supaya
     * halaman Upload Queue — yang sudah ada sejak 7.7 dan tidak tahu apa-apa
     * tentang aset — ikut menampilkan yang benar tanpa disunting.
     */
    public function getTargetLabelAttribute(): string
    {
        if ($this->type === self::TYPE_DRAMA_ASSET) {
            return $this->assetTargetLabel();
        }

        $episode = $this->episode;

        if ($episode === null) {
            return 'Part sudah dihapus';
        }

        return sprintf(
            '%s — Part %s',
            $episode->drama?->title ?: 'Tanpa drama',
            str_pad((string) (int) $episode->episode_number, 2, '0', STR_PAD_LEFT)
        );
    }

    protected function assetTargetLabel(): string
    {
        $drama = $this->drama;

        if ($drama === null) {
            return 'Drama sudah dihapus';
        }

        $jenis = DramaAssetType::tryFrom((string) $this->asset_type)?->label()
            ?: 'Aset';

        return sprintf('%s — %s', $drama->title, $jenis);
    }

    /** Keterangan tujuan penyimpanan yang diminta. */
    public function getTargetStorageAttribute(): string
    {
        return $this->storage_mode === 'manual'
            ? ($this->requestedProvider?->name ?: 'Provider terpilih (sudah dihapus)')
            : 'Auto — provider default';
    }
}
