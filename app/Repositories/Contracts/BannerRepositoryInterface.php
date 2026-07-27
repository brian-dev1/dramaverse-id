<?php

namespace App\Repositories\Contracts;

interface BannerRepositoryInterface
{
    public function active();

    public function admin();

    public function create(array $data);

    public function update($banner,array $data);

    public function delete($banner);
}