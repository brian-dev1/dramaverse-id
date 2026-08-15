<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kali pengiriman katalog ke channel Telegram.
 *
 * Lihat migrasi `create_channel_posts_table` untuk alasan tiap kolomnya.
 */
class ChannelPost extends Model
{
    public const STATUS_SENT   = 'sent';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO   = 'auto';

    protected $fillable = [
        'drama_id',
        'from_episode',
        'to_episode',
        'chat_id',
        'message_ids',
        'episode_count',
        'source',
        'status',
        'error',
        'sent_by',
    ];

    protected $casts = [
        'message_ids'   => 'array',
        'from_episode'  => 'integer',
        'to_episode'    => 'integer',
        'episode_count' => 'integer',
    ];

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function berhasil(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** Rentang episode dalam bentuk yang bisa dibaca. */
    public function rentang(): string
    {
        if ($this->from_episode === null && $this->to_episode === null) {
            return 'Semua part';
        }

        return $this->from_episode === $this->to_episode
            ? 'Part '.$this->from_episode
            : 'Part '.$this->from_episode.'–'.$this->to_episode;
    }
}
