<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $fillable = [

        'disk',

        'directory',

        'filename',

        'original_name',

        'mime_type',

        'extension',

        'size',

        'url',

        'uploaded_by',

    ];

    protected $casts = [

        'size'=>'integer',

    ];

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }
}