<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Drama;
use App\Models\Watchlist;
use App\Repositories\Contracts\WatchlistRepositoryInterface;

class WatchlistRepository implements WatchlistRepositoryInterface
{
    public function all(User $user)
    {
        return Watchlist::with('drama')
            ->where('user_id',$user->id)
            ->latest()
            ->get();
    }

    public function updateStatus(
        User $user,
        Drama $drama,
        string $status
    ){

        return Watchlist::updateOrCreate(

            [
                'user_id'=>$user->id,
                'drama_id'=>$drama->id
            ],

            [
                'status'=>$status
            ]

        );

    }

    public function delete(
        User $user,
        Drama $drama
    ){

        Watchlist::where([
            'user_id'=>$user->id,
            'drama_id'=>$drama->id
        ])->delete();

    }
}