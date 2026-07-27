<?php

namespace App\Repositories;

use App\Models\Country;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\Genre;
use App\Repositories\Contracts\SearchRepositoryInterface;

class SearchRepository implements SearchRepositoryInterface
{
    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [
                'dramas' => collect(),
                'episodes' => collect(),
                'genres' => collect(),
                'countries' => collect(),
            ];
        }

        return [
            'dramas' => Drama::query()
                ->with([
                    'genre',
                    'country',
                ])
                ->where(function ($query) use ($keyword) {
                    $query->where('title', 'like', "%{$keyword}%")
                          ->orWhere('slug', 'like', "%{$keyword}%");
                })
                ->latest()
                ->limit(10)
                ->get(),

            'episodes' => Episode::query()
                ->with('drama')
                ->where('title', 'like', "%{$keyword}%")
                ->orderBy('episode_number')
                ->limit(10)
                ->get(),

            'genres' => Genre::query()
                ->where('name', 'like', "%{$keyword}%")
                ->orderBy('name')
                ->limit(10)
                ->get(),

            'countries' => Country::query()
                ->where('name', 'like', "%{$keyword}%")
                ->orderBy('name')
                ->limit(10)
                ->get(),
        ];
    }
}