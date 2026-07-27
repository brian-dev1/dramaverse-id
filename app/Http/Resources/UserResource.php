<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'telegram_id' => $this->telegram_id,

            'telegram_username' => $this->telegram_username,

            'telegram_first_name' => $this->telegram_first_name,

            'telegram_last_name' => $this->telegram_last_name,

            'avatar' => $this->avatar,

            'is_premium' => $this->is_premium,

            'premium_expired_at' => $this->premium_expired_at,

            'last_login_at' => $this->last_login_at,

        ];
    }
}