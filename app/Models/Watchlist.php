<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Watchlist extends Model
{
    protected $fillable=[
        'user_id',
        'drama_id',
        'status'
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