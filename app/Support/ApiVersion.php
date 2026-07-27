<?php

namespace App\Support;

class ApiVersion
{
    public static function current(): string
    {
        return app()->has('api.version')

            ? app('api.version')

            : 'v1';
    }
}