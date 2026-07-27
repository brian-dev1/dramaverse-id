<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'progress' => $this->progress,

            'completed' => $this->completed,

            'completed_at' => $this->completed_at,

            'last_watched_at' => $this->last_watched_at,

            'drama' => new DramaResource(
                $this->whenLoaded('drama')
            ),

            'episode' => new EpisodeResource(
                $this->whenLoaded('episode')
            ),

        ];
    }
}