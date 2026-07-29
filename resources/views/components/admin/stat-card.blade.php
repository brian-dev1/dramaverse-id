@props([
    'label',
    'value',
    'icon'   => null,
    'href'   => null,
    'suffix' => null,
    'money'  => false,
])

@php
    $display = $money
        ? 'Rp '.number_format((float) $value, 0, ',', '.')
        : number_format((float) $value, 0, ',', '.');

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif class="stat-card">
    @if ($icon)
        <span class="stat-icon"><x-web.home.icon :name="$icon" :size="16" /></span>
    @endif

    <span class="stat-value">{{ $display }}@if ($suffix)<small>{{ $suffix }}</small>@endif</span>
    <span class="stat-label">{{ $label }}</span>
</{{ $tag }}>
