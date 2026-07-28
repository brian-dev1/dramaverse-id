@props(['banners' => null, 'dramas' => null])

@php
    // Utamakan banner yang dikurasi admin; bila kosong, pakai drama unggulan.
    $slides = collect();

    if ($banners && $banners->isNotEmpty()) {
        $slides = $banners->map(fn ($b) => (object) [
            'eyebrow'  => 'Sedang Tayang',
            'title'    => $b->title,
            'subtitle' => $b->subtitle,
            'image'    => $b->image,
            'href'     => $b->link ?: route('web.trending'),
            'cta'      => $b->button_text ?: 'Tonton Sekarang',
            'meta'     => [],
        ]);
    } elseif ($dramas && $dramas->isNotEmpty()) {
        $slides = $dramas->take(5)->map(fn ($d) => (object) [
            'eyebrow'  => 'Sedang Tayang',
            'title'    => $d->title,
            'subtitle' => $d->synopsis,
            'image'    => $d->cover_url ?? $d->poster_url,
            'href'     => route('web.drama.show', $d->slug),
            'cta'      => 'Tonton Sekarang',
            'meta'     => array_filter([
                $d->rating > 0 ? '★ '.number_format((float) $d->rating, 1) : null,
                $d->release_year,
                $d->country?->name,
                $d->total_episode ? $d->total_episode.' Episode' : null,
            ]),
        ]);
    }

    $slide = $slides->first();
@endphp

<section class="hero">
    <div class="hero-frame" @if ($slide?->image) style="background-image:url('{{ $slide->image }}')" @endif>

        @if ($slide)
            <div class="hero-content">

                <div class="hero-eyebrow"><span class="line"></span>{{ $slide->eyebrow }}</div>

                <h1 class="hero-title">{{ $slide->title }}</h1>

                @if (! empty($slide->meta))
                    <div class="hero-meta">
                        @foreach ($slide->meta as $i => $meta)
                            <span class="{{ $i === 0 ? 'rating' : 'chip' }}">{{ $meta }}</span>
                        @endforeach
                    </div>
                @endif

                @if ($slide->subtitle)
                    <p class="hero-desc">{{ Str::limit($slide->subtitle, 180) }}</p>
                @endif

                <div class="hero-actions">
                    <a href="{{ $slide->href }}" class="btn btn-primary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                        {{ $slide->cta }}
                    </a>
                    <a href="{{ $slide->href }}" class="btn btn-ghost">Lihat Detail</a>
                </div>

            </div>

            @if ($slides->count() > 1)
                <div class="hero-dots">
                    @foreach ($slides as $i => $s)
                        <span class="{{ $i === 0 ? 'active' : '' }}"></span>
                    @endforeach
                </div>
            @endif
        @else
            <div class="hero-content">
                <div class="hero-eyebrow"><span class="line"></span>DramaVerse ID</div>
                <h1 class="hero-title">Drama Asia,<br>tanpa jeda.</h1>
                <p class="hero-desc">
                    Katalog sedang disiapkan. Jalankan <code>php artisan migrate --seed</code>
                    untuk mengisi data contoh.
                </p>
                <div class="hero-actions">
                    <a href="{{ route('web.trending') }}" class="btn btn-primary">Jelajahi Katalog</a>
                </div>
            </div>
        @endif

    </div>
</section>
