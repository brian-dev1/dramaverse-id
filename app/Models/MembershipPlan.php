<?php

namespace App\Models;

use App\Enums\PaymentRegion;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'region',
        'price',
        'currency',
        'duration',
        'sort_order',
        'description',
        'benefits',
        'badge',
        'is_active',
    ];

    protected $casts = [
        'region'     => PaymentRegion::class,
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

    /**
     * Paket satu wilayah saja.
     *
     * Dipakai bot dan website supaya orang yang memilih "bayar dari luar
     * Indonesia" tidak melihat paket berharga Rupiah yang tidak bisa ia bayar
     * dengan alat bayar di tangannya — dan sebaliknya.
     */
    public function scopeRegion(Builder $query, PaymentRegion|string $region): Builder
    {
        $nilai = $region instanceof PaymentRegion ? $region->value : $region;

        return $query->where('region', $nilai);
    }

    /** Harga siap tampil beserta mata uangnya. */
    public function hargaTampil(): string
    {
        return Uang::format($this->price, $this->currency);
    }

    /** Paket gratis tidak dihitung sebagai langganan berbayar. */
    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }
}
