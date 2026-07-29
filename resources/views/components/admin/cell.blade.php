@props(['record', 'path'])

@php
    $value = data_get($record, $path);
    $isImage = in_array($path, ['poster', 'cover', 'image', 'thumbnail'], true);
@endphp

@if ($isImage)
    @if ($value)
        <img src="{{ asset('storage/'.$value) }}" alt="" class="cell-thumb" loading="lazy">
    @else
        <span class="cell-thumb cell-thumb-empty"><x-web.home.icon name="image" :size="14" /></span>
    @endif

@elseif (is_bool($value))
    <span class="badge {{ $value ? 'badge-on' : 'badge-off' }}">{{ $value ? 'Ya' : 'Tidak' }}</span>

@elseif ($value instanceof \Illuminate\Support\Carbon)
    <time datetime="{{ $value->toDateString() }}">{{ $value->translatedFormat('d M Y') }}</time>

@elseif ($path === 'status')
    <span class="badge badge-status">{{ ucfirst((string) $value) }}</span>

@elseif ($value === null || $value === '')
    <span class="cell-empty">—</span>

@else
    {{ $value }}
@endif
