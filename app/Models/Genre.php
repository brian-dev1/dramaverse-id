<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Genre extends Model
{
    protected $fillable = [

        'name',
        'slug',

    ];

    public function dramas(): HasMany
    {
        return $this->hasMany(Drama::class);
    }
}