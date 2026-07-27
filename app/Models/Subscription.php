<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'membership_plan_id',
        'price',
        'started_at',
        'expired_at',
        'status',
        'payment_reference'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }
}