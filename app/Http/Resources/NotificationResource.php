<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'title' => $this->title,

            'message' => $this->message,

            'type' => $this->type,

            'data' => $this->data,

            'is_read' => ! is_null(
                $this->read_at
            ),

            'read_at' => $this->read_at,

            'created_at' => $this->created_at,

        ];
    }
}