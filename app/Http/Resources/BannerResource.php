<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id'=>$this->id,

            'title'=>$this->title,

            'subtitle'=>$this->subtitle,

            'image'=>$this->image,

            'link'=>$this->link,

            'button_text'=>$this->button_text,

            'position'=>$this->position,

            'sort_order'=>$this->sort_order,

            'is_active'=>$this->is_active,

            'start_at'=>$this->start_at,

            'end_at'=>$this->end_at,

        ];
    }
}