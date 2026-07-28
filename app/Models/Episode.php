<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    use HasFactory;

    protected $fillable = [
        'drama_id',
        'episode_number',
        'title',
        'slug',
        'description',
        'video_url',
        'embed_url',
        'thumbnail',
        'duration',
        'is_vip',
        'views',
        'air_date',
        'status',
        'published_at',
        'expired_at',
    ];

    protected $casts = [
        'episode_number' => 'integer',
        'duration'       => 'integer',
        'views'          => 'integer',
        'is_vip'         => 'boolean',
        'air_date'       => 'datetime',
        'published_at'   => 'datetime',
        'expired_at'     => 'datetime',
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

    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }

    /** Episode berikutnya dalam drama yang sama. */
    public function next(): ?self
    {
        return static::where('drama_id', $this->drama_id)
            ->where('episode_number', '>', $this->episode_number)
            ->orderBy('episode_number')
            ->first();
    }

    /** Episode sebelumnya dalam drama yang sama. */
    public function previous(): ?self
    {
        return static::where('drama_id', $this->drama_id)
            ->where('episode_number', '<', $this->episode_number)
            ->orderByDesc('episode_number')
            ->first();
    }

    /** Durasi dalam format mm:ss untuk ditampilkan. */
    public function getDurationForHumansAttribute(): string
    {
        $minutes = intdiv($this->duration, 60);
        $seconds = $this->duration % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
