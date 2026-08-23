@props([
    'drama',
    'variant' => 'default',   // default | continue | latest | rank
    'rank'     => null,
    'progress' => null,
    'episode'  => null,

    // Poster yang sudah pasti terlihat begitu halaman dibuka. Diberikan hanya
    // pada beberapa kartu pertama di bagian teratas beranda. Sisanya tetap
    // `lazy`: itu justru yang membuat halaman ringan.
    'priority' => false,
])

{{--
    Struktur baru (namespace "dv-") supaya tidak bertabrakan dengan aturan
    kartu lama. Poster punya rasio tetap, judul berada DI BAWAH poster.
--}}
<a href="{{ route('web.drama.show', $drama->slug) }}"
   class="dv-card"
   aria-label="{{ $drama->title }}">

    <div class="dv-poster {{ $drama->gradient ?? 'g1' }}">

        @if ($drama->poster_url)
            {{--
                `loading="lazy"` menunda unduhan sampai browser selesai
                menghitung tata letak. Untuk poster di bawah layar itu tepat,
                tapi untuk poster yang langsung terlihat ia justru menambah satu
                putaran tunggu sebelum gambar pertama muncul. Kartu teratas
                karena itu diminta lebih dulu dan diberi prioritas tinggi.
            --}}
            @php
                // Turunan 360 piksel dipakai sebagai kandidat kecil. Bila
                // posternya diunggah sebelum turunan ini ada, nilainya null
                // dan img-nya kembali persis seperti sebelumnya.
                $kecil = $drama->poster_thumb_url;
            @endphp

            <img src="{{ $drama->poster_url }}"
                 @if ($kecil)
                     srcset="{{ $kecil }} 360w, {{ $drama->poster_url }} 600w"
                     sizes="(max-width: 640px) 33vw, (max-width: 900px) 25vw, 190px"
                 @endif
                 alt=""
                 loading="{{ $priority ? 'eager' : 'lazy' }}"
                 @if ($priority) fetchpriority="high" @endif
                 decoding="async"
                 width="300" height="450">
        @endif

        <span class="dv-shade" aria-hidden="true"></span>

        <div class="dv-tags">
            @switch($variant)
                @case('continue')
                    <span class="dv-tag">EP {{ str_pad($episode ?? 1, 2, '0', STR_PAD_LEFT) }}</span>
                    @break

                @case('latest')
                    <span class="dv-tag dv-tag-new">BARU</span>
                    @break
            @endswitch

            @if ($drama->is_vip)
                <span class="dv-tag dv-tag-vip">VIP</span>
            @endif
        </div>

        @if ($variant === 'rank' && $rank)
            <span class="dv-rank">{{ $rank }}</span>
        @endif

        <span class="dv-play" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                <path d="M8 5v14l11-7z"/>
            </svg>
        </span>

        @if ($variant === 'continue')
            <span class="dv-bar"><i style="width:{{ $progress ?? 0 }}%"></i></span>
        @endif
    </div>

    <div class="dv-meta">
        <span class="dv-title">{{ $drama->title }}</span>

        <span class="dv-sub">
            @if ($variant === 'continue')
                {{ $progress ?? 0 }}% selesai
            @else
                @if ($drama->relationLoaded('country') && $drama->country)
                    {{ $drama->country->name }}
                @endif
                @if ($drama->total_episode)
                    {{ ($drama->relationLoaded('country') && $drama->country) ? '· ' : '' }}{{ $drama->total_episode }} EP
                @endif
            @endif
        </span>
    </div>
</a>
