<?php

namespace App\Repositories;

use App\Models\Drama;
use App\Filters\DramaFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Repositories\Contracts\DramaCatalogRepositoryInterface;

class DramaCatalogRepository implements DramaCatalogRepositoryInterface
{
    public function __construct(
        protected DramaFilter $filter
    ) {
    }

    public function paginate(): LengthAwarePaginator
    {
        return $this->filter

            ->apply(

                Drama::query()

                    ->with([

                        'country',

                        'genres',

                    ])

            )

            ->paginate(

                request()->integer(
                    'per_page',
                    20
                )

            )

            ->withQueryString();
    }
}