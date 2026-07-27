<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchHistory extends Model
{
    protected $table = 'watch_histories';

    protected $fillable = [
        'user_id',
        'drama_id',
        'episode_id',
        'progress',
        'completed',
        'completed_at',
        'last_watched_at',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
        'last_watched_at' => 'datetime',
    ];

    /**
     * User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Drama
     */
    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    /**
     * Episode
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /**
     * Check whether the watch history has been completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Mark this watch history as completed.
     */
    public function markCompleted(): void
    {
        $this->forceFill([
            'completed' => true,
            'completed_at' => now(),
            'progress' => 100,
            'last_watched_at' => now(),
        ])->save();
    }
}