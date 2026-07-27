<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DramaFilter
{
    public function __construct(
        protected Request $request
    ) {
    }

    public function apply(
        Builder $query
    ): Builder {

        if ($this->request->filled('genre')) {

            $query->where(
                'genre_id',
                $this->request->integer('genre')
            );

        }

        if ($this->request->filled('country')) {

            $query->where(
                'country_id',
                $this->request->integer('country')
            );

        }

        if ($this->request->filled('year')) {

            $query->where(
                'release_year',
                $this->request->integer('year')
            );

        }

        if ($this->request->filled('status')) {

            $query->where(
                'status',
                $this->request->status
            );

        }

        switch ($this->request->get('sort')) {

            case 'oldest':
                $query->oldest();
                break;

            case 'title':
                $query->orderBy('title');
                break;

            case 'trending':
                $query
                    ->orderByDesc('is_trending')
                    ->latest();
                break;

            default:
                $query->latest();

        }

        return $query;
    }
}