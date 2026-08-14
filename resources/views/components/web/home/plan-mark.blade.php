@props(['tier' => 1, 'size' => 44])

{{--
    Medali tingkat paket.

    ## Kenapa bukan ikon di dalam kotak berwarna

    Bentuk sebelumnya — mahkota putih di atas gradasi datar — terbaca sebagai
    ikon antarmuka, bukan sebagai penanda nilai. Yang membedakan keduanya bukan
    warnanya melainkan permukaannya: benda yang terasa eksklusif selalu punya
    sisi yang memantulkan cahaya, tepi yang tertangkap sinar, dan bidang yang
    tidak rata. Bidang datar berwarna terlihat seperti tombol.

    Karena itu medalinya digambar sebagai segi enam bersudut dengan empat
    lapisan: gradasi dasar, permukaan miring yang lebih terang di kiri-atas,
    garis tepi logam yang menangkap cahaya, dan facet gelap di separuh bawah
    supaya bentuknya terbaca cekung. Semuanya di dalam satu SVG — tanpa gambar,
    tanpa berkas tambahan, dan tetap tajam di layar kepadatan berapa pun.

    ## Kilaunya hanya di tingkat teratas

    Kilau yang menyapu setiap medali akan berhenti berarti dan berubah jadi
    kebisingan. Ia disediakan hanya untuk seumur hidup dan paket tahunan —
    tepatnya karena itu yang paling ingin dilihat orang. Gerakannya dimatikan
    penuh di CSS bila sistem meminta pengurangan animasi.

    Tingkatnya dihitung dari jumlah hari, bukan urutan baris. Lihat
    MembershipController::tier().
--}}

@php
    $t = max(1, min(5, (int) $tier));

    // Id gradasi harus unik per medali: satu halaman memuat tujuh SVG, dan
    // id yang bertabrakan membuat semuanya memakai gradasi milik yang pertama.
    $uid = $t.'-'.substr(md5(uniqid('', true)), 0, 6);

    // [dasar gelap, dasar terang, kilau tepi, warna lambang]
    $palet = [
        1 => ['#39445E', '#6B7BA3', 'rgba(203,217,255,.85)', '#EAF0FF'],
        2 => ['#0E4F7D', '#3FA3DC', 'rgba(180,228,255,.9)',  '#F2FBFF'],
        3 => ['#4A2597', '#9B6BF0', 'rgba(214,190,255,.9)',  '#F8F4FF'],
        4 => ['#8E1F52', '#F0629B', 'rgba(255,196,220,.9)',  '#FFF5F9'],
        5 => ['#8A5A05', '#FFD36B', 'rgba(255,238,190,.95)', '#3A2405'],
    ][$t];

    [$gelap, $terang, $tepi, $lambang] = $palet;

    // Segi enam bersudut atas. Dipakai berulang: isi, facet, dan kliping.
    $segi = '22,2.2 39.3,12.1 39.3,31.9 22,41.8 4.7,31.9 4.7,12.1';
@endphp

<span {{ $attributes->merge(['class' => 'vip-plan-mark tier-'.$t]) }} aria-hidden="true">
    <svg width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 44 44"
         fill="none" focusable="false">

        <defs>
            <linearGradient id="pmDasar{{ $uid }}" x1="0.15" y1="0" x2="0.85" y2="1">
                <stop offset="0"   stop-color="{{ $terang }}"/>
                <stop offset="0.55" stop-color="{{ $gelap }}"/>
                <stop offset="1"   stop-color="{{ $gelap }}"/>
            </linearGradient>

            {{-- Tepi logam: terang di kiri-atas tempat cahaya jatuh, redup di
                 kanan-bawah. Tepi yang terang merata terlihat seperti garis
                 tebal, bukan seperti logam. --}}
            <linearGradient id="pmTepi{{ $uid }}" x1="0.1" y1="0" x2="0.9" y2="1">
                <stop offset="0"   stop-color="{{ $tepi }}"/>
                <stop offset="0.45" stop-color="rgba(255,255,255,.18)"/>
                <stop offset="1"   stop-color="{{ $tepi }}" stop-opacity=".55"/>
            </linearGradient>

            <linearGradient id="pmKilau{{ $uid }}" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0"   stop-color="#fff" stop-opacity="0"/>
                <stop offset="0.5" stop-color="#fff" stop-opacity=".55"/>
                <stop offset="1"   stop-color="#fff" stop-opacity="0"/>
            </linearGradient>

            <clipPath id="pmKlip{{ $uid }}">
                <polygon points="{{ $segi }}"/>
            </clipPath>
        </defs>

        {{-- Bayangan tipis di bawah medali. --}}
        <ellipse cx="22" cy="41" rx="12" ry="2.4" fill="#000" opacity=".28"/>

        <polygon points="{{ $segi }}" fill="url(#pmDasar{{ $uid }})"/>

        {{-- Facet: separuh bawah digelapkan sedikit sehingga permukaannya
             terbaca sebagai dua bidang, bukan satu bidang rata. --}}
        <g clip-path="url(#pmKlip{{ $uid }})">
            <polygon points="4.7,22 39.3,22 39.3,42 22,42 4.7,42" fill="#000" opacity=".16"/>
            <polygon points="22,2.2 39.3,12.1 22,22 4.7,12.1" fill="#fff" opacity=".13"/>

            @if ($t === 5)
                {{-- Kilau menyapu. Hanya tingkat teratas. --}}
                <polygon class="pm-kilau" points="-16,-6 -4,-6 8,50 -4,50"
                         fill="url(#pmKilau{{ $uid }})">
                    <animateTransform attributeName="transform" type="translate"
                                      values="0 0; 66 0; 66 0" dur="3.4s"
                                      keyTimes="0; 0.45; 1" repeatCount="indefinite"/>
                </polygon>
            @endif
        </g>

        <polygon points="{{ $segi }}" fill="none"
                 stroke="url(#pmTepi{{ $uid }})" stroke-width="1.5" stroke-linejoin="round"/>

        {{-- Lambang, diskalakan dari kanvas 24 ke tengah medali. --}}
        <g transform="translate(22 21.6) scale(0.78) translate(-12 -12)"
           fill="{{ $lambang }}">

            @switch($t)

                @case(1)
                    <path d="M12 4.2l1.4 4.9 4.9 1.4-4.9 1.4L12 16.8l-1.4-4.9-4.9-1.4 4.9-1.4z"/>
                    @break

                @case(2)
                    <path d="M12 3.6l2.5 5.2 5.7.7-4.2 3.9 1.1 5.6-5.1-2.8-5.1 2.8 1.1-5.6-4.2-3.9 5.7-.7z"/>
                    @break

                @case(3)
                    <path d="M7.6 4.4h8.8l3.4 4.7L12 19.9 4.2 9.1z"/>
                    <path d="M4.2 9.1h15.6M9.2 4.4L8 9.1l4 10.8 4-10.8-1.2-4.7"
                          stroke="rgba(0,0,0,.3)" stroke-width="1" fill="none" stroke-linejoin="round"/>
                    @break

                @case(4)
                    <path d="M3.8 8.9l4.1 3 4.1-6.6 4.1 6.6 4.1-3-1.8 9.5H5.6z"/>
                    <circle cx="3.8" cy="7.9" r="1.5"/>
                    <circle cx="20.2" cy="7.9" r="1.5"/>
                    <circle cx="12" cy="4.3" r="1.7"/>
                    @break

                @default
                    <path d="M8.7 15c-1.9 0-3.3-1.4-3.3-3.1s1.4-3.1 3.3-3.1c1.5 0 2.4.8 3.1 1.8l.9 1.3c.7 1 1.6 1.8 3.1 1.8 1.9 0 3.3-1.4 3.3-3.1s-1.4-3.1-3.3-3.1c-1.5 0-2.4.8-3.1 1.8l-.9 1.3c-.7 1-1.6 1.8-3.1 1.8z"
                          fill="none" stroke="{{ $lambang }}" stroke-width="2.2" stroke-linecap="round"/>
            @endswitch

        </g>
    </svg>
</span>
