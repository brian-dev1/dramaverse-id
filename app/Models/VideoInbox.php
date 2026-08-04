<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoInbox extends Model
{
    protected $fillable = [
        'storage_provider_id',
        'telegram_message_id',
        'original_filename',
        'object_key',
        'mime_type',
        'size',
        'checksum',
        'public_url',
        'status',
        'episode_id',
        'uploaded_at',
        'assigned_at',
    ];

    protected $casts = [
        'storage_provider_id' => 'integer',
        'telegram_message_id' => 'integer',
        'size'                => 'integer',
        'episode_id'          => 'integer',
        'uploaded_at'         => 'datetime',
        'assigned_at'         => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            StorageProvider::class,
            'storage_provider_id'
        );
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available'
            && $this->episode_id === null;
    }
}