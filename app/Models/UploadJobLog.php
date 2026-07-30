<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu peristiwa dalam riwayat sebuah pekerjaan unggah.
 *
 * Hanya ditulis, tidak pernah diubah. Log yang bisa disunting bukan log.
 */
class UploadJobLog extends Model
{
    protected $fillable = [
        'upload_job_id',
        'level',
        'event',
        'message',
        'context',
    ];

    protected $casts = [
        'upload_job_id' => 'integer',
        'context'       => 'array',
    ];

    public function uploadJob(): BelongsTo
    {
        return $this->belongsTo(UploadJob::class);
    }

    /**
     * Kelas warna baris log.
     *
     * `match` di sini punya arm `default` dengan sengaja: `level` datang dari
     * kolom string biasa, bukan enum, sehingga nilai apa pun yang pernah
     * ditulis versi kode lain tidak boleh membuat halaman melempar
     * UnhandledMatchError.
     */
    public function getLevelClassAttribute(): string
    {
        return match ($this->level) {
            'error'   => 'log-error',
            'warning' => 'log-warn',
            default   => 'log-info',
        };
    }
}
