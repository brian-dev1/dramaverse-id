@props(['tier' => 1, 'size' => 20])

{{--
    Lambang tingkat paket.

    ## Kenapa bukan mahkota untuk semuanya

    Sebelumnya setiap kartu memakai mahkota yang sama, dibedakan hanya oleh
    warna lingkaran yang ditentukan `nth-child` — ungu, hijau, biru, merah,
    magenta, berulang. Warnanya tidak berasal dari apa pun: paket 1 hari
    mendapat ungu karena kebetulan berada di urutan pertama, dan akan berubah
    jadi hijau begitu admin menambah satu paket di atasnya.

    Deretan lambang identik berwarna-warni membuat mata bekerja tanpa dibayar:
    ia menuntut perhatian seperti penanda yang berarti, lalu tidak menandai
    apa-apa. Sementara satu hal yang benar-benar ingin diketahui orang saat
    menyapu daftar — mana yang kecil, mana yang besar — tidak diwakili apa pun.

    Sekarang lambangnya naik bersama masa berlakunya: percikan, bintang,
    permata, mahkota, lalu tak terhingga untuk seumur hidup. Warnanya ikut
    menghangat dari biru ke emas. Tingkatnya dihitung dari jumlah hari, bukan
    dari urutan baris, jadi menambah paket baru tidak mengacak yang lain.
--}}

@php
    $t = max(1, min(5, (int) $tier));
    $w = (int) $size;
@endphp

<span {{ $attributes->merge(['class' => 'vip-plan-mark tier-'.$t]) }}>
    <svg width="{{ $w }}" height="{{ $w }}" viewBox="0 0 24 24"
         fill="none" aria-hidden="true" focusable="false">

        @switch($t)

            @case(1)
                {{-- Percikan: satu kilau kecil. --}}
                <path d="M12 3.2l1.5 5.3 5.3 1.5-5.3 1.5L12 16.8l-1.5-5.3L5.2 10l5.3-1.5z"
                      fill="currentColor"/>
                @break

            @case(2)
                {{-- Bintang lima sudut. --}}
                <path d="M12 3l2.6 5.6 6.1.8-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6L3.3 9.4l6.1-.8z"
                      fill="currentColor"/>
                @break

            @case(3)
                {{-- Permata, lengkap dengan garis segi supaya terbaca sebagai
                     batu, bukan segitiga. --}}
                <path d="M7.2 3.6h9.6l3.7 5.1L12 20.6 3.5 8.7z" fill="currentColor"/>
                <path d="M3.5 8.7h17M9 3.6l-1.4 5.1L12 20.6l4.4-11.9-1.4-5.1"
                      stroke="rgba(0,0,0,.28)" stroke-width="1.1" stroke-linejoin="round"/>
                @break

            @case(4)
                {{-- Mahkota. --}}
                <path d="M3 8.4l4.4 3.2L12 4.4l4.6 7.2L21 8.4l-1.9 10.2H4.9z"
                      fill="currentColor"/>
                <circle cx="3" cy="7.4" r="1.7" fill="currentColor"/>
                <circle cx="21" cy="7.4" r="1.7" fill="currentColor"/>
                <circle cx="12" cy="3.4" r="1.9" fill="currentColor"/>
                @break

            @default
                {{-- Tak terhingga: satu-satunya lambang yang menyatakan
                     "tidak pernah habis" tanpa perlu kata. --}}
                <path d="M8.6 15.4c-2.1 0-3.6-1.5-3.6-3.4S6.5 8.6 8.6 8.6c1.6 0 2.6.9 3.4 2l1 1.4c.8 1.1 1.8 2 3.4 2 2.1 0 3.6-1.5 3.6-3.4s-1.5-3.4-3.6-3.4c-1.6 0-2.6.9-3.4 2l-1 1.4c-.8 1.1-1.8 2-3.4 2z"
                      stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/>
        @endswitch

    </svg>
</span>
