<?php

namespace App\Repositories\Contracts;

interface SettingRepositoryInterface
{
    public function all();

    public function group(string $group);

    public function get(string $key);

    public function set(
        string $key,
        mixed $value
    );
}