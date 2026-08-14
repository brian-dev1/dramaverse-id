@props(['region', 'size' => 16])

{{--
    Bendera wilayah sebagai SVG, bukan emoji.

    Emoji bendera dibentuk dari sepasang "regional indicator" — 🇮🇩 sebenarnya
    huruf I dan D. Font sistem yang tidak punya glifnya menampilkan kedua huruf
    itu apa adanya, dan Windows termasuk yang tidak punya: di sana 🇮🇩 tampil
    sebagai "ID" dan 🇲🇾 sebagai "MY". Bukan bug peramban, melainkan keputusan
    Microsoft yang berlaku di seluruh Windows.

    Karena separuh pengunjung memakai Windows, bendera emoji berarti separuh
    pengunjung tidak pernah melihat benderanya. SVG dirender sama di mana pun.

    Rasio 4:3 dipakai untuk Indonesia dan 2:1 untuk Malaysia — perbandingan
    resmi masing-masing. Menyeragamkannya akan membuat salah satunya terlihat
    seperti bendera negara lain.
--}}

@php
    $w = (int) $size;
@endphp

@switch($region->value)

    @case('ID')
        <svg class="flag" width="{{ $w }}" height="{{ round($w * 0.75) }}"
             viewBox="0 0 12 9" aria-hidden="true" focusable="false">
            <rect width="12" height="4.5" fill="#CE1126"/>
            <rect y="4.5" width="12" height="4.5" fill="#FFF"/>
        </svg>
        @break

    @case('MY')
        <svg class="flag" width="{{ $w }}" height="{{ round($w * 0.5) }}"
             viewBox="0 0 28 14" aria-hidden="true" focusable="false">
            {{-- 14 lajur: tujuh merah, tujuh putih. --}}
            <rect width="28" height="14" fill="#FFF"/>
            @for ($i = 0; $i < 7; $i++)
                <rect y="{{ $i * 2 }}" width="28" height="1" fill="#CC0001"/>
            @endfor
            {{-- Kanton menutupi delapan lajur teratas. --}}
            <rect width="14" height="8" fill="#010066"/>
            {{-- Bulan sabit: lingkaran kuning ditumpuk lingkaran biru. --}}
            <circle cx="5.6" cy="4" r="2.4" fill="#FFCC00"/>
            <circle cx="6.5" cy="4" r="2" fill="#010066"/>
            {{-- Bintang, disederhanakan jadi bentuk bersudut banyak. --}}
            <path fill="#FFCC00" d="M9.6 1.9l.5 1.6 1.7.0-1.3 1 .5 1.6-1.4-1-1.4 1 .5-1.6-1.3-1 1.7.0z"/>
        </svg>
        @break

    @default
        {{-- Negara lain: bola dunia, bukan bendera. Tidak ada bendera yang
             mewakili "sisanya", dan memakai salah satu di antaranya akan
             menyesatkan. --}}
        <svg class="flag flag-globe" width="{{ $w }}" height="{{ $w }}"
             viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
            <circle cx="8" cy="8" r="6.6" stroke="currentColor" stroke-width="1.3"/>
            <ellipse cx="8" cy="8" rx="2.9" ry="6.6" stroke="currentColor" stroke-width="1.1"/>
            <path d="M1.6 6h12.8M1.6 10h12.8" stroke="currentColor" stroke-width="1.1"/>
        </svg>
@endswitch
