@props([
    'name',
    'label',
    'type'  => 'text',
    'value' => null,
    'hint'  => null,
    'options' => [],
    'required' => false,
    'rows' => 4,
])

@php
    $id  = 'field-'.$name;
    $val = old($name, $value);
    $err = $errors->first($name);
@endphp

<div class="field {{ $err ? 'field-invalid' : '' }}">

    <label for="{{ $id }}">
        {{ $label }}
        @if ($required)<span class="field-required" aria-hidden="true">*</span>@endif
    </label>

    @switch($type)
        {{--
            `{{ $attributes }}` harus ada di SETIAP cabang, bukan hanya di
            @default. Sebelumnya hanya cabang input teks yang meneruskannya,
            sehingga atribut yang dikirim ke select dibuang tanpa suara — dan
            `data-next-numbers` di halaman Tambah Episode Massal tidak pernah
            sampai ke DOM, membuat pengisian nomor episode otomatis diam-diam
            tidak berfungsi sejak dibuat.
        --}}
        @case('textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
                      class="control" {{ $attributes }}>{{ $val }}</textarea>
            @break

        @case('select')
            <select id="{{ $id }}" name="{{ $name }}" class="control" {{ $attributes }}>
                <option value="">— pilih —</option>
                @foreach ($options as $k => $v)
                    <option value="{{ $k }}" @selected((string) $val === (string) $k)>{{ $v }}</option>
                @endforeach
            </select>
            @break

        @case('multiselect')
            <div class="checkbox-grid">
                @foreach ($options as $k => $v)
                    <label class="checkbox-item">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $k }}"
                               @checked(in_array($k, (array) old($name, $value ?? []), false))>
                        {{ $v }}
                    </label>
                @endforeach
            </div>
            @break

        @case('checkbox')
            <label class="switch">
                <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1"
                       @checked((bool) $val)>
                <span class="switch-track"><span class="switch-thumb"></span></span>
                <span class="switch-label">{{ $hint ?? 'Aktif' }}</span>
            </label>
            @break

        @default
            <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
                   value="{{ $val }}" class="control"
                   {{ $attributes }}>
    @endswitch

    @if ($hint && $type !== 'checkbox')
        <p class="field-hint">{{ $hint }}</p>
    @endif

    @if ($err)
        <p class="field-error">{{ $err }}</p>
    @endif

</div>
