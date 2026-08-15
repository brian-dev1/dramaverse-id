@extends('web.layouts.app')

@section('title', $drama->title.' — Part '.$episode->episode_number)

@section('content')

    @php($telegramLink = $episode->video?->isSyncedToTelegram()
        ? \App\Support\TelegramDeepLink::watch($episode)
        : null)

    @php($posterImage = $drama->poster_url ?: $drama->cover_url ?: $episode->thumbnail_url)

    <section class="player-wrap section-pad">

        <div class="player-main">

            <div class="player-poster-card">
                <div class="player-poster" @if ($posterImage) style="background-image:url('{{ $posterImage }}')" @endif>
                    @if ($telegramLink)
                        <a href="{{ $telegramLink }}" class="player-play-btn"
                           target="_blank" rel="noopener" aria-label="Tonton di Telegram">
                            <x-web.home.icon name="play" :size="20" />
                        </a>
                    @else
                        <div class="player-play-btn player-play-btn-disabled">
                            <x-web.home.icon name="play" :size="20" />
                        </div>
                    @endif
                </div>
            </div>

            <div class="player-info">

                <a href="{{ route('web.drama.show', $drama->slug) }}" class="see-all"><x-web.home.icon name="arrow-left" :size="13" /> {{ $drama->title }}</a>

                <h1 class="page-title">
                    Part {{ $episode->episode_number }}@if ($episode->title) — {{ $episode->title }}@endif
                </h1>

                {{--
                    Deep link ke bot. Hanya dirender bila episodenya memang
                    sudah ada di Telegram — tombol yang menjanjikan tontonan
                    lalu dijawab "belum siap" oleh bot adalah dead link versi
                    lintas aplikasi, dan aturan proyek ini melarangnya.
                --}}
                @if ($telegramLink)
                    <a href="{{ $telegramLink }}" class="btn btn-primary"
                       target="_blank" rel="noopener">
                        <x-web.home.icon name="play" :size="14" />
                        Tonton di Telegram
                    </a>
                @else
                    <p class="player-poster-note">Video belum siap ditonton.</p>
                @endif

                @if ($episode->description)
                    <p class="page-subtitle">{{ $episode->description }}</p>
                @endif

                <div class="player-nav">
                    @if ($previousEpisode ?? null)
                        <a href="{{ route('web.episode.show', $previousEpisode->id) }}" class="btn btn-ghost">
                            <x-web.home.icon name="arrow-left" :size="14" /> Part {{ $previousEpisode->episode_number }}
                        </a>
                    @endif

                    @if ($nextEpisode ?? null)
                        <a href="{{ route('web.episode.show', $nextEpisode->id) }}" class="btn btn-primary">
                            Part {{ $nextEpisode->episode_number }} <x-web.home.icon name="arrow-right" :size="14" />
                        </a>
                    @endif
                </div>

            </div>
        </div>

        <aside class="player-sidebar">
            <h2 class="section-title">Semua Part</h2>

            <div class="episode-list episode-list-compact">
                @forelse ($episodes as $ep)
                    <a href="{{ route('web.episode.show', $ep->id) }}"
                       class="episode-item {{ $ep->id === $episode->id ? 'active' : '' }}">
                        <span class="episode-number">{{ str_pad($ep->episode_number, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="episode-title">{{ $ep->title ?: 'Part '.$ep->episode_number }}</span>
                        @if ($ep->is_vip)<span class="chip gold">VIP</span>@endif
                    </a>
                @empty
                    <p class="page-subtitle">Belum ada part lain.</p>
                @endforelse
            </div>
        </aside>

    </section>

@endsection