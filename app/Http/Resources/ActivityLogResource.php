<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id'=>$this->id,

            'user_id'=>$this->user_id,

            'module'=>$this->module,

            'action'=>$this->action,

            'description'=>$this->description,

            'ip_address'=>$this->ip_address,

            'payload'=>$this->payload,

            'created_at'=>$this->created_at,

        ];
    }
}