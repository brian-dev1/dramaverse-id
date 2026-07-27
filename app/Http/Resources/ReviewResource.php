<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id'=>$this->id,

            'rating'=>$this->rating,

            'review'=>$this->review,

            'is_spoiler'=>$this->is_spoiler,

            'created_at'=>$this->created_at,

            'user'=>new UserResource($this->whenLoaded('user'))

        ];
    }
}