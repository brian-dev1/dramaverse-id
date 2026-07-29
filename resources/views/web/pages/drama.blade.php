@extends('web.layouts.app')

@section('title', $drama->title)
@section('description', Str::limit($drama->synopsis, 155))

@section('content')

    {{-- HERO DETAIL --}}
    <section class="detail-hero {{ $drama->gradient ?? 'g1' }}"
             @if ($drama->cover_url) style="background-image:url('{{ $drama->cover_url }}')" @endif>

        <div class="detail-hero-inner section-pad">

            <div class="detail-poster {{ $drama->gradient ?? 'g1' }}">
                @if ($drama->poster_url)
                    <img src="{{ $drama->poster_url }}" alt="{{ $drama->title }}">
                @else
                    <span class="detail-poster-title">{{ $drama->title }}</span>
                @endif
            </div>

            <div class="detail-body">

                <h1 class="hero-title">{{ $drama->title }}</h1>

                @if ($drama->original_title)
                    <p class="detail-original">{{ $drama->original_title }}</p>
                @endif

                <div class="hero-meta">
                    @if ($drama->rating > 0)
                        <span class="rating">&#9733; {{ number_format((float) $drama->rating, 1) }}</span>
                    @endif
                    @if ($drama->release_year)
                        <span class="chip">{{ $drama->release_year }}</span>
                    @endif
                    @if ($drama->country)
                        <span class="chip">{{ $drama->country->flag_emoji }} {{ $drama->country->name }}</span>
                    @endif
                    <span class="chip">
                        {{ ['ongoing' => 'Sedang Tayang', 'completed' => 'Tamat', 'upcoming' => 'Akan Tayang'][$drama->status] ?? $drama->status }}
                    </span>
                    @if ($drama->total_episode)
                        <span class="chip gold">{{ $drama->total_episode }} Episode</span>
                    @endif
                    @if ($drama->is_vip)
                        <span class="chip gold">VIP</span>
                    @endif
                </div>

                @if ($drama->genres->isNotEmpty())
                    <div class="pill-row detail-genres">
                        @foreach ($drama->genres as $genre)
                            <a href="{{ route('web.genre.show', $genre->slug) }}" class="pill">{{ $genre->name }}</a>
                        @endforeach
                    </div>
                @endif

                @if ($drama->synopsis)
                    <p class="hero-desc">{{ $drama->synopsis }}</p>
                @endif

                <div class="hero-actions">

                    @if ($drama->episodes->isNotEmpty())
                        <a href="{{ route('web.episode.show', $drama->episodes->first()->id) }}" class="btn btn-primary">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                            Tonton Episode 1
                        </a>
                    @endif

                    @auth
                        <form method="POST" action="{{ route('web.favorites.toggle', $drama->slug) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">
                                {{ $isFavorite ? 'Hapus dari Favorit' : 'Tambah ke Favorit' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('web.my-list.toggle', $drama->slug) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">
                                {{ $inMyList ? 'Hapus dari Daftar' : 'Tambah ke Daftar Saya' }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('web.membership') }}" class="btn btn-ghost">Masuk untuk Menyimpan</a>
                    @endauth

                </div>

            </div>
        </div>
    </section>

    {{-- DAFTAR EPISODE --}}
    <section class="section section-pad">

        <x-web.home.section-header
            title="Daftar Episode"
            :count="$drama->episodes->count().' episode'" />

        @if ($drama->episodes->isEmpty())
            <p class="page-subtitle">Episode belum tersedia.</p>
        @else
            <div class="episode-list">
                @foreach ($drama->episodes as $episode)
                    <a href="{{ route('web.episode.show', $episode->id) }}" class="episode-item">
                        <span class="episode-number">{{ str_pad($episode->episode_number, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="episode-title">{{ $episode->title ?: 'Episode '.$episode->episode_number }}</span>
                        <span class="episode-meta">
                            @if ($episode->is_vip)<span class="chip gold">VIP</span>@endif
                            @if ($episode->duration)<span>{{ $episode->duration_for_humans }}</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

    </section>

    {{-- DRAMA TERKAIT --}}
    <x-web.home.grid :dramas="$related" title="Drama Serupa" />

@endsection

@section('promo')
    @guest
        <x-web.home.membership-banner />
    @endguest
@endsection
