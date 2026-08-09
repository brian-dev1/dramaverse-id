<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // total_episode NOT NULL dengan default di database.
        // Field kosong dari form mengirim null dan menabrak constraint.
        foreach (['total_episode'] as $kolomBerdefault) {
            if ($this->has($kolomBerdefault) && blank($this->input($kolomBerdefault))) {
                $this->request->remove($kolomBerdefault);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'max:255', Rule::unique('dramas', 'slug')],
            'original_title' => ['nullable', 'string', 'max:255'],
            'synopsis'       => ['nullable', 'string'],

            'genres'         => ['required', 'array', 'min:1'],
            'genres.*'       => ['integer', 'exists:genres,id'],
            'country_id'     => ['required', 'integer', 'exists:countries,id'],

            'poster'         => ['nullable', 'string', 'max:255'],
            'cover'          => ['nullable', 'string', 'max:255'],
            'trailer_url'    => ['nullable', 'url', 'max:255'],
            'gradient'       => ['nullable', 'string', 'max:8'],

            'total_episode'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status'         => ['required', Rule::in(['ongoing', 'completed', 'upcoming'])],

            'is_vip'         => ['boolean'],
            'is_featured'    => ['boolean'],
            'is_trending'    => ['boolean'],
            'published_at'   => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'genres.required'  => 'Pilih minimal satu genre.',
            'country_id.required' => 'Negara wajib dipilih.',
        ];
    }
}
