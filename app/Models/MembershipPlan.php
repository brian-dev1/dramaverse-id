<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'price',
        'duration',
        'sort_order',
        'description',
        'benefits',
        'badge',
        'is_active',
    ];

    protected $casts = [
        'price'      => 'decimal:2',
        'duration'   => 'integer',
        'sort_order' => 'integer',
        'benefits'   => 'array',
        'is_active'  => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('price');
    }

    /** Paket gratis tidak dihitung sebagai langganan berbayar. */
    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }
}
