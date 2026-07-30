<?php

namespace App\Models;

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
        'type',
        'episode_id',
        'requested_provider_id',
        'storage_mode',
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
        'requested_provider_id' => 'integer',
        'episode_video_id'      => 'integer',
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

    public function video(): BelongsTo
    {
        return $this->belongsTo(EpisodeVideo::class, 'episode_video_id');
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

    /** Label episode untuk tabel: "Judul Drama — Episode 07". */
    public function getTargetLabelAttribute(): string
    {
        $episode = $this->episode;

        if ($episode === null) {
            return 'Episode sudah dihapus';
        }

        return sprintf(
            '%s — Episode %s',
            $episode->drama?->title ?: 'Tanpa drama',
            str_pad((string) (int) $episode->episode_number, 2, '0', STR_PAD_LEFT)
        );
    }

    /** Keterangan tujuan penyimpanan yang diminta. */
    public function getTargetStorageAttribute(): string
    {
        return $this->storage_mode === 'manual'
            ? ($this->requestedProvider?->name ?: 'Provider terpilih (sudah dihapus)')
            : 'Auto — provider default';
    }
}
