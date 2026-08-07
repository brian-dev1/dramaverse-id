@props([
    'text'  => 'DramaVerse',
    'badge' => 'ID',
    'href'  => null,
])

@php
    $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $tag = $href ? 'a' : 'span';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'brand-logo']) }}>
    <span class="brand-wordmark" aria-label="{{ $text }}">
        @foreach ($letters as $i => $ch)
            <span class="brand-letter" style="--d:{{ $i * 0.09 }}s" aria-hidden="true">{{ $ch }}</span>
        @endforeach
        <span class="brand-shine" aria-hidden="true">{{ $text }}</span>
    </span>
    @if ($badge)
        <span class="brand-badge">{{ $badge }}</span>
    @endif
</{{ $tag }}>
