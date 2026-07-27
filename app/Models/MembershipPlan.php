<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    protected $fillable = [
        'name',
        'price',
        'duration',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}