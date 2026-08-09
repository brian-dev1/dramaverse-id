<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralCommission extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_user_id',
        'invoice_id',
        'base_amount',
        'rate',
        'amount',
        'level',
        'status',
        'available_at',
        'note',
    ];

    protected $casts = [
        'base_amount'  => 'decimal:2',
        'rate'         => 'decimal:2',
        'amount'       => 'decimal:2',
        'level'        => 'integer',
        'available_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
