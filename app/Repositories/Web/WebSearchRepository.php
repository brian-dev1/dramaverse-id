<?php

namespace App\Repositories\Web;

use App\Models\Drama;
use Illuminate\Http\Request;

class WebSearchRepository
{
    public function search(Request $request)
    {

        $query = Drama::query()

            ->with([

                'country',

                'genres'

            ])

            ->where(

                'status',

                'published'

            );

        if($request->filled('keyword')){

            $query->where(function($q) use ($request){

                $q->where(

                    'title',

                    'LIKE',

                    '%'.$request->keyword.'%'

                )

                ->orWhere(

                    'original_title',

                    'LIKE',

                    '%'.$request->keyword.'%'

                );

            });

        }

        if($request->filled('genre')){

            $query->whereHas(

                'genres',

                function($q) use($request){

                    $q->where(

                        'slug',

                        $request->genre

                    );

                }

            );

        }

        if($request->filled('country')){

            $query->whereHas(

                'country',

                function($q) use($request){

                    $q->where(

                        'slug',

                        $request->country

                    );

                }

            );

        }

        if($request->filled('year')){

            $query->where(

                'year',

                $request->year

            );

        }

        return $query

            ->latest()

            ->paginate(20);

    }
}