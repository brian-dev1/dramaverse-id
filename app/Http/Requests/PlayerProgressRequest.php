<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlayerProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'episode_id' => ['required', 'integer', 'exists:episodes,id'],
            'progress' => ['required', 'integer', 'min:0'],
        ];
    }
}