<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    protected $fillable = [
        'drama_id',
        'episode_number',
        'title',
        'video_url',
        'duration',
        'thumbnail',
        'status',
        'published_at',
        'expired_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    /**
     * Drama pemilik episode.
     */
    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    /**
     * Riwayat tontonan episode ini.
     */
    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    /**
     * Apakah episode sudah dipublikasikan.
     */
    public function isPublished(): bool
    {
        if ($this->published_at === null) {
            return false;
        }

        return $this->published_at->isPast();
    }

    /**
     * Apakah episode sudah kedaluwarsa.
     */
    public function isExpired(): bool
    {
        if ($this->expired_at === null) {
            return false;
        }

        return $this->expired_at->isPast();
    }
}