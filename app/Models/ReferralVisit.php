<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['referrer_id', 'ip', 'user_agent', 'fingerprint', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
