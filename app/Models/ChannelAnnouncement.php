<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pengumuman ke channel Telegram.
 *
 * Lihat migrasi `create_channel_announcements_table` untuk alasan tiap
 * kolomnya.
 */
class ChannelAnnouncement extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT      = 'sent';

    public const STATUS_FAILED    = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'body',
        'image',
        'buttons',
        'scheduled_at',
        'status',
        'chat_id',
        'message_id',
        'error',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'buttons'      => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
        'message_id'   => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function berhasil(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function menunggu(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** Bisa dibatalkan selama belum terkirim. */
    public function bisaDibatalkan(): bool
    {
        return $this->menunggu();
    }

    /**
     * Pengumuman terjadwal yang waktunya sudah tiba.
     *
     * `scheduled_at` yang null TIDAK ikut: itu pengumuman "kirim sekarang"
     * yang pengirimannya sudah diurus saat tombol ditekan. Kalau ia sampai
     * tersangkut di status scheduled — misalnya prosesnya mati di tengah —
     * ia sengaja dibiarkan, karena memungutnya di sini berarti pengumuman
     * yang mungkin sudah terkirim dikirim untuk kedua kalinya.
     */
    public function scopeJatuhTempo(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SENT      => 'Terkirim',
            self::STATUS_FAILED    => 'Gagal',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default                => 'Terjadwal',
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::STATUS_SENT      => 'badge-on',
            self::STATUS_FAILED    => 'badge-off',
            self::STATUS_CANCELLED => '',
            default                => 'badge-pending',
        };
    }
}
