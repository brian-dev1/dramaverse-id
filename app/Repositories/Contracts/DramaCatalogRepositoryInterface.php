<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DramaCatalogRepositoryInterface
{
    public function paginate(): LengthAwarePaginator;
}