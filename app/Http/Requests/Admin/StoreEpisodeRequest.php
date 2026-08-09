<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreEpisodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'drama_id' => 'required|exists:dramas,id',

            'episode_number' => 'required|integer|min:1',

            'title' => 'required|string|max:255',

            'description' => 'nullable|string',

            'thumbnail' => 'nullable|string',

            'video_url' => 'required|string',

            'access_type' => 'required|string',

        ];
    }
}