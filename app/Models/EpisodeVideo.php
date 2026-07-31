<?php

namespace App\Models;

use App\Support\Bytes;
use App\Enums\TelegramSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata satu berkas video episode yang sudah tersimpan.
 *
 * Baris ini adalah satu-satunya jalan aplikasi menjangkau berkasnya kembali.
 * `object_key` saja tidak cukup — `storage_provider_id` harus ikut, karena
 * key yang sama bisa ada di beberapa bucket, dan provider default bisa
 * berpindah kapan saja. Keduanya selalu dibaca bersama.
 *
 * `checksum` disimpan untuk memastikan berkas yang ada di penyimpanan masih
 * berkas yang sama dengan yang diunggah. Dihitung dari berkas sementara
 * SEBELUM dikirim, sehingga nilainya mewakili apa yang dimaksud pengunggah,
 * bukan apa yang kebetulan ada di bucket sekarang.
 */
class EpisodeVideo extends Model
{
    protected $fillable = [
        'episode_id',
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
        'uploaded_at',
        'telegram_file_id',
        'telegram_unique_file_id',
        'telegram_message_id',
        'sync_status',
        'synced_at',
        'last_error',
        'retry_count',
    ];

    protected $casts = [
        'episode_id'          => 'integer',
        'storage_provider_id' => 'integer',
        'uploaded_by'         => 'integer',
        'size'                => 'integer',
        'uploaded_at'         => 'datetime',
        'telegram_message_id' => 'integer',
        'sync_status'         => TelegramSyncStatus::class,
        'synced_at'           => 'datetime',
        'retry_count'         => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
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
    | Turunan
    |--------------------------------------------------------------------------
    */

    public function getSizeForHumansAttribute(): string
    {
        return Bytes::forHumans($this->size);
    }

    /**
     * Checksum yang dipendekkan untuk ditampilkan di tabel.
     *
     * Delapan karakter pertama sudah cukup untuk membandingkan dua berkas
     * secara sekilas, sementara nilai utuhnya tetap tersimpan untuk
     * pemeriksaan yang sungguhan.
     */
    public function getChecksumShortAttribute(): string
    {
        return substr((string) $this->checksum, 0, 8);
    }

    /**
     * Berkas ini masih bisa dijangkau?
     *
     * Provider yang sudah dihapus (soft delete) membuat relasinya kosong,
     * dan itu berarti berkasnya masih ada di bucket tetapi aplikasi tidak
     * lagi punya kredensial untuk menjangkaunya.
     */
    public function isReachable(): bool
    {
        return $this->provider !== null && $this->provider->isUsable();
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    /** Sudah punya file_id yang bisa dipakai mengirim ulang tanpa mengunggah. */
    public function isSyncedToTelegram(): bool
    {
        return $this->sync_status === TelegramSyncStatus::SYNCED
            && filled($this->telegram_file_id);
    }
}
