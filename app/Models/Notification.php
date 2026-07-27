<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [

        'user_id',

        'title',

        'message',

        'type',

        'data',

        'read_at',

    ];

    protected $casts = [

        'data' => 'array',

        'read_at' => 'datetime',

    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    public function isRead(): bool
    {
        return ! is_null(
            $this->read_at
        );
    }
}