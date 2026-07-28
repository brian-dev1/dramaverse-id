@props([
    'banners' => collect(),
])

<section class="hero">

    <div class="hero-frame">

        @if($banners->count())

            @foreach($banners as $index => $banner)

                <div
                    class="hero-slide {{ $index == 0 ? 'active' : '' }}"

                    data-slide="{{ $index }}"

                    style="background-image:
                        linear-gradient(rgba(11,10,16,.35),rgba(11,10,16,.88)),
                        url('{{ $banner->backdrop_url }}');
                        background-size:cover;
                        background-position:center;">

                    <div class="hero-content">

                        <div class="hero-eyebrow">

                            <span class="line"></span>

                            {{ $banner->label ?? 'Sedang Tayang' }}

                        </div>

                        <h1 class="hero-title">

                            {{ $banner->title }}

                        </h1>

                        <div class="hero-meta">

                            <span class="rating">

                                ★ {{ number_format($banner->rating,1) }}

                            </span>

                            <span class="chip">

                                {{ $banner->year }}

                            </span>

                            <span class="chip">

                                {{ $banner->country }}

                            </span>

                            <span class="chip gold">

                                {{ $banner->episodes }} Episode

                            </span>

                        </div>

                        <p class="hero-desc">

                            {{ $banner->description }}

                        </p>

                        <div class="hero-actions">

                            <a
                                href="{{ route('web.watch',$banner->slug) }}"
                                class="btn btn-primary">

                                <svg
                                    width="15"
                                    height="15"
                                    viewBox="0 0 24 24"
                                    fill="currentColor">

                                    <path d="M8 5v14l11-7z"/>

                                </svg>

                                Tonton Sekarang

                            </a>

                            <a
                                href="{{ route('web.detail',$banner->slug) }}"
                                class="btn btn-ghost">

                                Lihat Detail

                            </a>

                        </div>

                    </div>

                </div>

            @endforeach

        @else

            {{-- Placeholder ketika database belum terhubung --}}

            <div class="hero-slide active">

                <div class="hero-content">

                    <div class="hero-eyebrow">

                        <span class="line"></span>

                        Sedang Tayang

                    </div>

                    <h1 class="hero-title">

                        DramaVerse ID

                    </h1>

                    <div class="hero-meta">

                        <span class="rating">

                            ★ 0.0

                        </span>

                        <span class="chip">

                            2026

                        </span>

                        <span class="chip">

                            Drama

                        </span>

                        <span class="chip gold">

                            0 Episode

                        </span>

                    </div>

                    <p class="hero-desc">

                        Hero banner akan otomatis mengambil data dari Hero Banner
                        setelah backend selesai dihubungkan.

                    </p>

                    <div class="hero-actions">

                        <button
                            class="btn btn-primary">

                            Tonton Sekarang

                        </button>

                        <button
                            class="btn btn-ghost">

                            Lihat Detail

                        </button>

                    </div>

                </div>

            </div>

        @endif

        <div class="hero-dots">

            @foreach($banners as $index => $banner)

                <span
                    class="{{ $index==0 ? 'active' : '' }}">

                </span>

            @endforeach

        </div>

    </div>

</section>