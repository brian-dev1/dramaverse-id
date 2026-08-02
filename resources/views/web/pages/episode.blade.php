@extends('web.layouts.app')

@section('title', $drama->title.' — Episode '.$episode->episode_number)

@section('content')

    <section class="player-wrap section-pad">

        <div class="player-main">

            <div class="player-frame">
                @if ($episode->embed_url)
                    <iframe src="{{ $episode->embed_url }}"
                            allowfullscreen loading="lazy"
                            title="{{ $drama->title }} Episode {{ $episode->episode_number }}"></iframe>
                @elseif ($episode->video_url)
                    <video id="player" controls playsinline preload="metadata"
                           @if ($episode->thumbnail_url) poster="{{ $episode->thumbnail_url }}" @endif
                           data-episode="{{ $episode->id }}"
                           data-progress="{{ $progress ?? 0 }}">
                        <source src="{{ $episode->video_url }}" type="video/mp4">
                        Peramban Anda tidak mendukung pemutar video.
                    </video>
                @else
                    <div class="player-empty">
                        <p>Sumber video belum diunggah untuk episode ini.</p>
                    </div>
                @endif
            </div>

            <div class="player-info">

                <a href="{{ route('web.drama.show', $drama->slug) }}" class="see-all"><x-web.home.icon name="arrow-left" :size="13" /> {{ $drama->title }}</a>

                <h1 class="page-title">
                    Episode {{ $episode->episode_number }}@if ($episode->title) — {{ $episode->title }}@endif
                </h1>

                {{--
                    Deep link ke bot. Hanya dirender bila episodenya memang
                    sudah ada di Telegram — tombol yang menjanjikan tontonan
                    lalu dijawab "belum siap" oleh bot adalah dead link versi
                    lintas aplikasi, dan aturan proyek ini melarangnya.
                --}}
                @php($telegramLink = $episode->video?->isSyncedToTelegram()
                    ? \App\Support\TelegramDeepLink::watch($episode)
                    : null)

                @if ($telegramLink)
                    <a href="{{ $telegramLink }}" class="btn btn-primary"
                       target="_blank" rel="noopener">
                        <x-web.home.icon name="play" :size="14" />
                        Tonton di Telegram
                    </a>
                @endif

                @if ($episode->description)
                    <p class="page-subtitle">{{ $episode->description }}</p>
                @endif

                <div class="player-nav">
                    @if ($previousEpisode ?? null)
                        <a href="{{ route('web.episode.show', $previousEpisode->id) }}" class="btn btn-ghost">
                            <x-web.home.icon name="arrow-left" :size="14" /> Episode {{ $previousEpisode->episode_number }}
                        </a>
                    @endif

                    @if ($nextEpisode ?? null)
                        <a href="{{ route('web.episode.show', $nextEpisode->id) }}" class="btn btn-primary">
                            Episode {{ $nextEpisode->episode_number }} <x-web.home.icon name="arrow-right" :size="14" />
                        </a>
                    @endif
                </div>

            </div>
        </div>

        <aside class="player-sidebar">
            <h2 class="section-title">Semua Episode</h2>

            <div class="episode-list episode-list-compact">
                @forelse ($episodes as $ep)
                    <a href="{{ route('web.episode.show', $ep->id) }}"
                       class="episode-item {{ $ep->id === $episode->id ? 'active' : '' }}">
                        <span class="episode-number">{{ str_pad($ep->episode_number, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="episode-title">{{ $ep->title ?: 'Episode '.$ep->episode_number }}</span>
                        @if ($ep->is_vip)<span class="chip gold">VIP</span>@endif
                    </a>
                @empty
                    <p class="page-subtitle">Belum ada episode lain.</p>
                @endforelse
            </div>
        </aside>

    </section>

@endsection
