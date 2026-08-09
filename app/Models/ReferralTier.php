<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralTier extends Model
{
    protected $fillable = ['level', 'rate', 'min_referrals', 'is_active'];

    protected $casts = [
        'level'         => 'integer',
        'rate'          => 'decimal:2',
        'min_referrals' => 'integer',
        'is_active'     => 'boolean',
    ];
}
