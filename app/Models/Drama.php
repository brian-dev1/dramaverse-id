<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Drama extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'poster',
        'cover',
        'country_id',
        'genre_id',
        'release_year',
        'total_episode',
        'status',
        'is_trending',
    ];

    protected $casts = [
        'is_trending' => 'boolean',
    ];

    /**
     * Country
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Genre
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /**
     * Episodes
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)
            ->orderBy('episode_number');
    }

    /**
     * Watch Histories
     */
    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }
}