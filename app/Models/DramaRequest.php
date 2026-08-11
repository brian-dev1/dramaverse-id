<?php

namespace App\Models;

use App\Enums\DramaRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu permintaan drama dari seorang pengguna.
 *
 * Lihat migrasi `create_drama_requests_table` untuk alasan bentuk kolomnya.
 */
class DramaRequest extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'year',
        'note',
        'status',
        'admin_note',
        'drama_id',
        'notified_at',
    ];

    protected $casts = [
        'status'      => DramaRequestStatus::class,
        'notified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeStatus(Builder $query, DramaRequestStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof DramaRequestStatus ? $status->value : $status);
    }

    /** Yang masih menunggu tindakan admin. */
    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DramaRequestStatus::PENDING->value,
            DramaRequestStatus::PROCESS->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Judul yang dinormalkan, dipakai mencari permintaan kembar.
     *
     * Huruf besar-kecil, spasi berlebih, dan tanda baca dibuang. "Reply 1988",
     * "reply  1988", dan "Reply-1988" adalah permintaan yang sama, dan
     * memperlakukannya sebagai tiga permintaan berbeda membuat daftar admin
     * penuh duplikat yang harus dibaca satu per satu.
     */
    public static function normalkan(string $judul): string
    {
        $bersih = mb_strtolower(trim($judul));

        $bersih = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $bersih) ?? $bersih;

        return trim(preg_replace('/\s+/', ' ', $bersih) ?? $bersih);
    }

    public function bolehDiberiTahu(): bool
    {
        return $this->status === DramaRequestStatus::AVAILABLE
            && $this->notified_at === null;
    }
}
