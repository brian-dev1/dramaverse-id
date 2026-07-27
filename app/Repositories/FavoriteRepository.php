<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Drama;
use App\Models\Favorite;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\FavoriteRepositoryInterface;

class FavoriteRepository implements FavoriteRepositoryInterface
{
    public function all(User $user): Collection
    {
        return Favorite::query()
            ->with('drama')
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }

    public function isFavorite(
        User $user,
        Drama $drama
    ): bool {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('drama_id', $drama->id)
            ->exists();
    }

    public function add(
        User $user,
        Drama $drama
    ): Favorite {
        return Favorite::firstOrCreate([
            'user_id' => $user->id,
            'drama_id' => $drama->id,
        ]);
    }

    public function remove(
        User $user,
        Drama $drama
    ): bool {
        return Favorite::query()
                ->where('user_id', $user->id)
                ->where('drama_id', $drama->id)
                ->delete() > 0;
    }
}