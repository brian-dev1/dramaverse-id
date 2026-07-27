<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [

        'user_id',

        'drama_id',

        'rating',

        'review',

        'is_spoiler',

        'is_hidden',

    ];

    protected $casts = [

        'is_spoiler'=>'boolean',

        'is_hidden'=>'boolean',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function drama()
    {
        return $this->belongsTo(Drama::class);
    }
}