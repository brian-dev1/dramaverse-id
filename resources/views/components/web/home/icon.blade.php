@props([
    'name',
    'size' => 14,
])

{{--
    Ikon SVG terpusat.

    Menggantikan karakter teks (★ ✓ → ←) dan emoji bendera yang
    perenderannya berbeda-beda antar sistem operasi — di Windows,
    emoji bendera tampil sebagai dua huruf, bukan gambar.
--}}

@php
    $paths = [
        'star'        => '<path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left'  => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'check'       => '<path d="M20 6L9 17l-5-5"/>',
        'play'        => '<path d="M8 5v14l11-7z"/>',
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'bell'        => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'home'        => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'trend'       => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
    ];

    $filled = in_array($name, ['star', 'play'], true);
@endphp

<svg class="icon icon-{{ $name }}"
     width="{{ $size }}" height="{{ $size }}"
     viewBox="0 0 24 24"
     fill="{{ $filled ? 'currentColor' : 'none' }}"
     stroke="{{ $filled ? 'none' : 'currentColor' }}"
     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? '' !!}
</svg>
