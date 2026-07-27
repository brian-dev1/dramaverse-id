<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'episode_number' => $this->episode_number,

            'title' => $this->title,

            'duration' => $this->duration,

            'thumbnail' => $this->thumbnail,

            'video_url' => $this->video_url,

            'drama_id' => $this->drama_id,

        ];
    }
}