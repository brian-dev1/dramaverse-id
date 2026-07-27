<?php

namespace App\Services;

use App\Repositories\Contracts\SettingRepositoryInterface;

class SettingService
{
    public function __construct(
        protected SettingRepositoryInterface $repository
    ){
    }

    public function all()
    {
        return $this->repository->all();
    }

    public function group(string $group)
    {
        return $this->repository->group($group);
    }

    public function get(string $key)
    {
        return $this->repository->get($key);
    }

    public function set(
        string $key,
        mixed $value
    )
    {
        return $this->repository->set(
            $key,
            $value
        );
    }
}