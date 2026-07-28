<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'link',
        'button_text',
        'position',
        'sort_order',
        'is_active',
        'start_at',
        'end_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
    ];

    /** Banner yang sedang tayang pada posisi tertentu. */
    public function scopeActive(Builder $query, string $position = 'hero'): Builder
    {
        return $query->where('is_active', true)
            ->where('position', $position)
            ->where(fn (Builder $q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->orderBy('sort_order');
    }
}
