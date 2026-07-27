<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingRepositoryInterface;

class SettingRepository implements SettingRepositoryInterface
{
    public function all()
    {
        return Setting::orderBy('group')->get();
    }

    public function group(string $group)
    {
        return Setting::where(
            'group',
            $group
        )->get();
    }

    public function get(string $key)
    {
        return Setting::where(
            'key',
            $key
        )->value('value');
    }

    public function set(
        string $key,
        mixed $value
    ) {

        return Setting::updateOrCreate(

            [
                'key'=>$key
            ],

            [
                'value'=>$value
            ]

        );

    }
}