<?php

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Episode;
use App\Repositories\Contracts\EpisodeSchedulerRepositoryInterface;

class EpisodeSchedulerRepository implements EpisodeSchedulerRepositoryInterface
{
    public function publishScheduled()
    {
        return Episode::query()

            ->where('status','scheduled')

            ->where('published_at','<=',Carbon::now())

            ->update([

                'status'=>'published'

            ]);
    }

    public function expireEpisodes()
    {
        return Episode::query()

            ->whereNotNull('expired_at')

            ->where('expired_at','<=',Carbon::now())

            ->update([

                'status'=>'archived'

            ]);
    }
}