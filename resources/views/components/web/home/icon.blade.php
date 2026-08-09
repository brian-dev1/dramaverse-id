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
        'trash'       => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'inbox'       => '<path d="M3 12h5l2 3h4l2-3h5M3 12l3-7h12l3 7v7H3z"/>',
        'edit'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'restore'     => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
        'film'        => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18M17 3v18M3 12h18"/>',
        'list'        => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'tag'         => '<path d="M20.6 13.4 12 22l-9-9V3h10z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18"/>',
        'image'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'users'       => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3.5-6 7-6s7 2 7 6"/><path d="M17 4a4 4 0 0 1 0 8"/>',
        'send'        => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'crown'       => '<path d="M3 7l4.5 4L12 4l4.5 7L21 7l-1.8 12H4.8z"/>',
        'gem'         => '<path d="M6 3h12l3 6-9 12L3 9z"/><path d="M3 9h18M9 3 6 9l6 12 6-12-3-6"/>',
        'no-ads'      => '<circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/>',
        'download'    => '<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>',
        'monitor'     => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'copy'        => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'link'        => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
        'wallet'      => '<path d="M3 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="7" width="18" height="12" rx="2"/><circle cx="17" cy="13" r="1.4"/>',
        'gift'        => '<rect x="3" y="8" width="18" height="4"/><path d="M5 12v9h14v-9M12 8v13"/><path d="M12 8S10 3 7.5 4.5 12 8 12 8zm0 0s2-5 4.5-3.5S12 8 12 8z"/>',
        'chart'       => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/>',
        'file'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'shield'      => '<path d="M12 2 4 6v6c0 5 3.4 8.6 8 10 4.6-1.4 8-5 8-10V6z"/>',
        // Tiga cakram bertumpuk — lambang penyimpanan, dipakai menu Storage Manager.
        'database'    => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        // Garis denyut — dipakai tombol Test Connection.
        'activity'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'menu'        => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close'       => '<path d="M18 6 6 18M6 6l12 12"/>',
        'sort'        => '<path d="m7 15 5 5 5-5M7 9l5-5 5 5"/>',
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
