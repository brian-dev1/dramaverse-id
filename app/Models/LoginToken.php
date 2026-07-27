<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'expired_at',
        'used_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}