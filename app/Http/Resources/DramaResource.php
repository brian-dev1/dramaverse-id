<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DramaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'title' => $this->title,

            'slug' => $this->slug,

            'synopsis' => $this->synopsis,

            'poster' => $this->poster,

            'cover' => $this->cover,

            'country' => $this->country?->name,

            'genres' => $this->whenLoaded('genres', fn () => $this->genres->pluck('name')),

            'total_episode' => $this->total_episode,

            'status' => $this->status,

            'is_trending' => $this->is_trending,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

        ];
    }
}