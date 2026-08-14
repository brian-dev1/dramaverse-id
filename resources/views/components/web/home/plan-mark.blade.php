@props(['tier' => 1, 'size' => 56])

{{--
    Medali tingkat paket.

    ## Kenapa besar, dan kenapa bercincin

    Versi sebelumnya berukuran 44px dan berdiri sendiri tanpa bingkai. Pada
    kartu setinggi 78px itu membuatnya terbaca sebagai ikon pelengkap — mata
    melewatinya dan langsung ke nama paket. Lambang yang tugasnya menyatakan
    tingkat harus cukup besar untuk dikenali sebelum teks apa pun dibaca;
    kalau tidak, ia hanya menghabiskan ruang.

    Sekarang 56px dengan cincin luar yang mengelilinginya. Cincin itu bukan
    hiasan: ia yang mengubah bentuknya dari "ikon berlatar warna" menjadi
    "medali" — sama seperti pada lencana pangkat, uang logam, dan lambang
    kesatuan, di mana bingkai melingkar itulah yang menyatakan bahwa isinya
    diberikan, bukan sekadar digambar.

    ## Empat lapisan permukaan

    Gradasi dasar, facet terang di kuadran atas, facet gelap di separuh bawah,
    dan tepi logam yang menangkap cahaya di kiri-atas lalu meredup ke
    kanan-bawah. Benda yang terasa mahal selalu punya sisi yang memantul
    berbeda-beda; bidang datar berwarna selalu terbaca sebagai tombol.

    ## Kilau hanya di puncak

    Kilau yang menyapu semua medali berhenti berarti dan berubah jadi
    kebisingan. Ia disediakan untuk seumur hidup dan paket tahunan saja —
    tepatnya karena itu yang paling ingin dilihat orang. Dimatikan penuh di
    CSS bila sistem meminta pengurangan animasi.

    Tingkatnya dihitung dari jumlah hari, bukan urutan baris. Lihat
    MembershipController::tier().
--}}

@php
    $t = max(1, min(5, (int) $tier));

    // Id gradasi harus unik per medali: satu halaman memuat tujuh SVG, dan
    // id yang bertabrakan membuat semuanya memakai gradasi milik yang pertama.
    $uid = $t.'-'.substr(md5(uniqid('', true)), 0, 6);

    // [dasar gelap, dasar terang, kilau tepi, warna lambang, cincin luar]
    [$gelap, $terang, $tepi, $lambang, $cincin] = [
        1 => ['#2E3852', '#7A8BB5', 'rgba(214,226,255,.9)', '#F2F6FF', 'rgba(148,168,214,.55)'],
        2 => ['#06456F', '#4FB4EC', 'rgba(190,234,255,.95)', '#F4FCFF', 'rgba(79,180,236,.55)'],
        3 => ['#3D1B86', '#A87BFF', 'rgba(222,203,255,.95)', '#FAF7FF', 'rgba(168,123,255,.55)'],
        4 => ['#7D1547', '#FF6FA6', 'rgba(255,205,225,.95)', '#FFF7FA', 'rgba(255,111,166,.55)'],
        5 => ['#7A4A02', '#FFDC80', 'rgba(255,244,206,1)',   '#3A2405', 'rgba(255,206,102,.7)'],
    ][$t];

    // Segi enam bersudut atas, pusat 28,28. Dipakai berulang: isi, facet,
    // kliping, dan tepi.
    $segi   = '28,4 48.8,16 48.8,40 28,52 7.2,40 7.2,16';
    $cincinSegi = '28,0.8 51.6,14.4 51.6,41.6 28,55.2 4.4,41.6 4.4,14.4';
@endphp

<span {{ $attributes->merge(['class' => 'vip-plan-mark tier-'.$t]) }} aria-hidden="true">
    <svg width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 56 56"
         fill="none" focusable="false">

        <defs>
            <linearGradient id="pmDasar{{ $uid }}" x1="0.12" y1="0" x2="0.88" y2="1">
                <stop offset="0"    stop-color="{{ $terang }}"/>
                <stop offset="0.5"  stop-color="{{ $gelap }}"/>
                <stop offset="1"    stop-color="{{ $gelap }}"/>
            </linearGradient>

            {{-- Tepi logam: terang tempat cahaya jatuh, redup di seberangnya.
                 Tepi yang terang merata terlihat seperti garis tebal, bukan
                 seperti logam. --}}
            <linearGradient id="pmTepi{{ $uid }}" x1="0.08" y1="0" x2="0.92" y2="1">
                <stop offset="0"    stop-color="{{ $tepi }}"/>
                <stop offset="0.42" stop-color="rgba(255,255,255,.16)"/>
                <stop offset="1"    stop-color="{{ $tepi }}" stop-opacity=".5"/>
            </linearGradient>

            <linearGradient id="pmKilau{{ $uid }}" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0"   stop-color="#fff" stop-opacity="0"/>
                <stop offset="0.5" stop-color="#fff" stop-opacity=".6"/>
                <stop offset="1"   stop-color="#fff" stop-opacity="0"/>
            </linearGradient>

            <clipPath id="pmKlip{{ $uid }}">
                <polygon points="{{ $segi }}"/>
            </clipPath>
        </defs>

        {{-- Cincin luar. Inilah yang membuatnya terbaca sebagai medali. --}}
        <polygon points="{{ $cincinSegi }}" fill="none"
                 stroke="{{ $cincin }}" stroke-width="1.4" stroke-linejoin="round"/>

        <ellipse cx="28" cy="51.5" rx="15" ry="2.8" fill="#000" opacity=".3"/>

        <polygon points="{{ $segi }}" fill="url(#pmDasar{{ $uid }})"/>

        <g clip-path="url(#pmKlip{{ $uid }})">
            {{-- Separuh bawah digelapkan dan kuadran atas diterangkan, supaya
                 permukaannya terbaca sebagai beberapa bidang, bukan satu
                 bidang rata. --}}
            <polygon points="7.2,28 48.8,28 48.8,54 7.2,54" fill="#000" opacity=".18"/>
            <polygon points="28,4 48.8,16 28,28 7.2,16" fill="#fff" opacity=".15"/>

            @if ($t === 5)
                <polygon class="pm-kilau" points="-20,-8 -6,-8 8,64 -6,64"
                         fill="url(#pmKilau{{ $uid }})">
                    <animateTransform attributeName="transform" type="translate"
                                      values="0 0; 84 0; 84 0" dur="3.4s"
                                      keyTimes="0; 0.45; 1" repeatCount="indefinite"/>
                </polygon>
            @endif
        </g>

        <polygon points="{{ $segi }}" fill="none"
                 stroke="url(#pmTepi{{ $uid }})" stroke-width="1.9" stroke-linejoin="round"/>

        {{-- Lambang, diskalakan dari kanvas 24 ke tengah medali. Sengaja
             mengisi hampir seluruh bidang: lambang kecil di tengah bidang
             luas terbaca sebagai tempelan, bukan sebagai lambang. --}}
        <g transform="translate(28 27.6) scale(1.08) translate(-12 -12)"
           fill="{{ $lambang }}">

            @switch($t)

                @case(1)
                    <path d="M12 3.4l1.7 5.4 5.4 1.7-5.4 1.7L12 17.6l-1.7-5.4-5.4-1.7 5.4-1.7z"/>
                    @break

                @case(2)
                    <path d="M12 3.2l2.6 5.4 5.9.8-4.3 4.1 1.1 5.9-5.3-2.9-5.3 2.9 1.1-5.9-4.3-4.1 5.9-.8z"/>
                    @break

                @case(3)
                    <path d="M7.4 4h9.2l3.6 4.9L12 20.4 3.8 8.9z"/>
                    <path d="M3.8 8.9h16.4M9.1 4L7.8 8.9 12 20.4l4.2-11.5L14.9 4"
                          stroke="rgba(0,0,0,.32)" stroke-width="1.05" fill="none" stroke-linejoin="round"/>
                    @break

                @case(4)
                    <path d="M3.4 8.6l4.3 3.2L12 4.9l4.3 6.9 4.3-3.2-1.9 10H5.3z"/>
                    <circle cx="3.4" cy="7.5" r="1.6"/>
                    <circle cx="20.6" cy="7.5" r="1.6"/>
                    <circle cx="12" cy="3.8" r="1.8"/>
                    <path d="M5.6 20.2h12.8" stroke="{{ $lambang }}" stroke-width="1.9" stroke-linecap="round"/>
                    @break

                @default
                    <path d="M8.5 15.6c-2.2 0-3.9-1.6-3.9-3.6s1.7-3.6 3.9-3.6c1.7 0 2.8.9 3.6 2.1l.9 1.4c.8 1.2 1.9 2.1 3.6 2.1 2.2 0 3.9-1.6 3.9-3.6s-1.7-3.6-3.9-3.6c-1.7 0-2.8.9-3.6 2.1l-.9 1.4c-.8 1.2-1.9 2.1-3.6 2.1z"
                          fill="none" stroke="{{ $lambang }}" stroke-width="2.6" stroke-linecap="round"/>
            @endswitch

        </g>
    </svg>
</span>
