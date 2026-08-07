<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'membership_plan_id',
        'invoice_id',
        'price',
        'started_at',
        'expired_at',
        'status',
        'payment_reference',
        'auto_renew',
        'cancelled_at',
        'source',
        'expiry_notified_at',
    ];

    protected $casts = [
        'started_at'         => 'datetime',
        'expired_at'         => 'datetime',
        'cancelled_at'       => 'datetime',
        'expiry_notified_at' => 'datetime',
        'auto_renew'         => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
