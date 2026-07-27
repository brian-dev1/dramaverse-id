<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'created_at' => $this->created_at,

            'drama' => new DramaResource(
                $this->whenLoaded('drama')
            ),

        ];
    }
}