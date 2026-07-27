<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Drama;

interface WatchlistRepositoryInterface
{
    public function all(User $user);

    public function updateStatus(
        User $user,
        Drama $drama,
        string $status
    );

    public function delete(
        User $user,
        Drama $drama
    );
}