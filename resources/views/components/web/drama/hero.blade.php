@php

$poster = $drama->poster
    ? asset($drama->poster)
    : asset('images/no-poster.jpg');

@endphp

<section
    class="drama-hero"
    style="background-image:url('{{ $poster }}')">

    <div class="hero-overlay">

        <div class="container hero-wrapper">

            <div class="hero-poster">

                <img
                    src="{{ $poster }}"
                    alt="{{ $drama->title }}">

            </div>

            <div class="hero-info">

                <span class="hero-badge">

                    {{ strtoupper($drama->status) }}

                </span>

                <h1>

                    {{ $drama->title }}

                </h1>

                <div class="hero-meta">

                    @if($drama->country)

                        <span>

                            🌏 {{ $drama->country->name }}

                        </span>

                    @endif

                    @if($drama->genre)

                        <span>

                            🎭 {{ $drama->genre->name }}

                        </span>

                    @endif

                    @if($drama->release_year)

                        <span>

                            📅 {{ $drama->release_year }}

                        </span>

                    @endif

                    <span>

                        🎬 {{ $drama->total_episode }} Episode

                    </span>

                </div>

                <div class="hero-action">

                    @if($drama->episodes->count())

                        <a
                            href="{{ route('episode.show',$drama->episodes->sortBy('episode_number')->first()) }}"
                            class="btn-primary">

                            ▶ Tonton Sekarang

                        </a>

                    @endif

                    <button
                        class="btn-secondary">

                        ❤ Favorit

                    </button>

                </div>

            </div>

        </div>

    </div>

</section>