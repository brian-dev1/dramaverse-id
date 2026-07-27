<?php

namespace App\Repositories\Contracts;

use App\Models\Episode;
use Illuminate\Database\Eloquent\Collection;

interface EpisodeRepositoryInterface
{
    public function find(int $id): ?Episode;

    public function detail(Episode $episode): Episode;

    public function next(Episode $episode): ?Episode;

    public function previous(Episode $episode): ?Episode;

    public function byDrama(int $dramaId): Collection;
}