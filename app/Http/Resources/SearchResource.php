<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'dramas' => DramaResource::collection(
                collect($this['dramas'])
            ),

            'episodes' => EpisodeResource::collection(
                collect($this['episodes'])
            ),

            'genres' => $this['genres'],

            'countries' => $this['countries'],

            'total_result' => count($this['dramas'])
                + count($this['episodes'])
                + count($this['genres'])
                + count($this['countries']),

        ];
    }
}