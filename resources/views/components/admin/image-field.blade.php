@props([
    'name',
    'label',
    'current' => null,
    'hint'    => 'JPG, PNG, atau WebP. Maksimal 4 MB.',
])

@php
    $id  = 'field-'.$name;
    $err = $errors->first($name);
@endphp

<div class="field {{ $err ? 'field-invalid' : '' }}">

    <label for="{{ $id }}">{{ $label }}</label>

    <div class="upload" data-upload>
        <div class="upload-preview" data-preview>
            @if ($current)
                <img src="{{ asset('storage/'.$current) }}" alt="">
            @else
                <span class="upload-empty">Belum ada gambar</span>
            @endif
        </div>

        <div class="upload-body">
            <input type="file" id="{{ $id }}" name="{{ $name }}"
                   accept="image/jpeg,image/png,image/webp"
                   class="upload-input" data-input>

            <label for="{{ $id }}" class="btn btn-ghost btn-sm">Pilih gambar</label>

            <p class="field-hint">{{ $hint }}</p>
            <p class="upload-name" data-name></p>
        </div>
    </div>

    @if ($err)
        <p class="field-error">{{ $err }}</p>
    @endif

</div>
