@props(['country', 'showLabel' => false])

@php
    $code = strtoupper((string) ($country->code ?? ''));
    $name = strcasecmp((string) $country->name, 'Tiongkok') === 0
        ? 'China'
        : (string) $country->name;
@endphp

{{-- SVG lokal: bendera tetap tampil di Windows/Android tanpa font emoji atau request gambar. --}}
<span class="country-badge country-flag" role="img" aria-label="Bendera {{ $name }}">
    @switch($code)
        @case('KR')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#fff"/><path d="M18 7a5 5 0 0 1 0 10 2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 0 0-5Z" fill="#cd2e3a"/><path d="M18 17a5 5 0 0 1 0-10 2.5 2.5 0 0 1 0 5 2.5 2.5 0 0 0 0 5Z" fill="#0047a0"/><g stroke="#111" stroke-width="1.15"><path d="m7 6 4-3m-3 5 4-3m16 13-4 3m3-5-4 3M28 6l-4-3m3 5-4-3M8 18l4 3m-3-5 4 3"/></g></svg>
            @break
        @case('CN')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#de2910"/><path fill="#ffde00" d="m7 3 1.1 3.1h3.3L8.8 8l1 3.1L7 9.2l-2.8 1.9 1-3.1-2.6-1.9h3.3Zm7.2.5.5 1.4h1.5l-1.2.9.5 1.4-1.3-.9-1.2.9.5-1.4-1.2-.9h1.5Zm3.1 3.4.5 1.4h1.5l-1.2.9.5 1.4-1.3-.9-1.2.9.5-1.4-1.2-.9h1.5Zm-.3 4.5.5 1.4H19l-1.2.9.5 1.4-1.3-.9-1.2.9.5-1.4-1.2-.9h1.5Zm-2.8 3.2.5 1.4h1.5l-1.2.9.5 1.4-1.3-.9-1.2.9.5-1.4-1.2-.9h1.5Z"/></svg>
            @break
        @case('TH')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#a51931"/><path fill="#f4f5f8" d="M0 4h36v16H0z"/><path fill="#2d2a4a" d="M0 8h36v8H0z"/></svg>
            @break
        @case('JP')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#fff"/><circle cx="18" cy="12" r="6" fill="#bc002d"/></svg>
            @break
        @case('TW')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#fe0000"/><path fill="#000095" d="M0 0h18v12H0z"/><circle cx="9" cy="6" r="3.2" fill="#fff"/><path fill="#fff" d="m9 1 .7 2.1L11.5 2l-.2 2.1 2-.5L12 5.3l2 .3-1.8 1.1 1.5 1.4-2.1-.3.8 2-1.8-1.2-.1 2.1L9 9.1l-1.5 1.6-.1-2.1-1.8 1.2.8-2-2.1.3 1.5-1.4L4 5.6l2-.3-1.3-1.7 2 .5L6.5 2l1.8 1.1Z"/></svg>
            @break
        @case('PH')
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="12" rx="2" fill="#0038a8"/><path d="M0 12h36v10a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2Z" fill="#ce1126"/><path d="m0 0 17 12L0 24Z" fill="#fff"/><circle cx="5.5" cy="12" r="2" fill="#fcd116"/></svg>
            @break
        @default
            <svg viewBox="0 0 36 24" aria-hidden="true"><rect width="36" height="24" rx="2" fill="#17141e"/><circle cx="18" cy="12" r="7" fill="none" stroke="#ff9b45"/><path d="M11 12h14M18 5c2.8 3.6 2.8 10.4 0 14m0-14c-2.8 3.6-2.8 10.4 0 14" fill="none" stroke="#ff9b45"/></svg>
    @endswitch
</span>
@if ($showLabel)<span class="country-label">{{ $name }}</span>@endif
