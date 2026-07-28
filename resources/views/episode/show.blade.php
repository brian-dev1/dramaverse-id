@extends('web.layouts.app')

@section('title', $episode->drama->title . ' - Episode ' . $episode->episode_number)

@section('content')

<div
    class="player-page"
    data-next-episode="{{ $nextEpisode ? route('episode.show',$nextEpisode) : '' }}">

    <div class="container">

        <div class="player-header">

            <div>

                <h1>

                    {{ $episode->drama->title }}

                </h1>

                <p>

                    Episode {{ $episode->episode_number }}

                    @if($episode->title)

                        • {{ $episode->title }}

                    @endif

                </p>

            </div>

            <div class="player-header-action">

                <a
                    href="{{ route('drama.show',$episode->drama->slug) }}"
                    class="player-back-button">

                    ← Kembali ke Drama

                </a>

            </div>

        </div>

        <div class="player-layout">

            <div class="player-main">

                <x-web.player.player
                    :episode="$episode"
                />

                <x-web.player.navigation
                    :episode="$episode"
                    :previousEpisode="$previousEpisode"
                    :nextEpisode="$nextEpisode"
                />

                <x-web.player.info
                    :episode="$episode"
                />

            </div>

            <aside class="player-sidebar">

                <x-web.player.episode-sidebar
                    :drama="$episode->drama"
                    :currentEpisode="$episode"
                />

            </aside>

        </div>

    </div>

</div>

@endsection