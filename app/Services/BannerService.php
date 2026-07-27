<?php

namespace App\Services;

use App\Repositories\Contracts\BannerRepositoryInterface;

class BannerService
{
    public function __construct(
        protected BannerRepositoryInterface $repository
    ){
    }

    public function active()
    {
        return $this->repository->active();
    }

    public function admin()
    {
        return $this->repository->admin();
    }

    public function create(array $data)
    {
        return $this->repository->create($data);
    }

    public function update($banner,array $data)
    {
        return $this->repository->update($banner,$data);
    }

    public function delete($banner)
    {
        $this->repository->delete($banner);
    }
}