<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Drama;
use App\Models\Favorite;
use Illuminate\Support\Collection;

interface FavoriteRepositoryInterface
{
    public function all(User $user): Collection;

    public function isFavorite(
        User $user,
        Drama $drama
    ): bool;

    public function add(
        User $user,
        Drama $drama
    ): Favorite;

    public function remove(
        User $user,
        Drama $drama
    ): bool;
}