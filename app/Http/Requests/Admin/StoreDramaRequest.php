<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'title'=>'required|string|max:255',

            'slug'=>'required|string|max:255',

            'genre_id'=>'required|exists:genres,id',

            'country_id'=>'required|exists:countries,id',

            'description'=>'nullable|string',

            'poster'=>'nullable|string',

            'cover'=>'nullable|string',

            'release_year'=>'nullable|integer',

            'status'=>'required|string',

        ];
    }
}