<?php

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Banner;
use App\Repositories\Contracts\BannerRepositoryInterface;

class BannerRepository implements BannerRepositoryInterface
{
    public function active()
    {
        return Banner::query()

            ->where('is_active',true)

            ->where(function($q){

                $q->whereNull('start_at')

                  ->orWhere('start_at','<=',Carbon::now());

            })

            ->where(function($q){

                $q->whereNull('end_at')

                  ->orWhere('end_at','>=',Carbon::now());

            })

            ->orderBy('sort_order')

            ->get();
    }

    public function admin()
    {
        return Banner::latest()->paginate(20);
    }

    public function create(array $data)
    {
        return Banner::create($data);
    }

    public function update($banner,array $data)
    {
        $banner->update($data);

        return $banner;
    }

    public function delete($banner)
    {
        $banner->delete();
    }
}