@props([
'previousEpisode'=>null,
'nextEpisode'=>null,
'episode'
])

<div class="player-navigation">

    @if($previousEpisode)

        <a
            href="{{ route('episode.show',$previousEpisode) }}"
            class="player-nav">

            ← Episode {{ $previousEpisode->episode_number }}

        </a>

    @else

        <div></div>

    @endif

    <a
        href="{{ route('drama.show',$episode->drama->slug) }}"
        class="player-back">

        Semua Episode

    </a>

    @if($nextEpisode)

        <a
            href="{{ route('episode.show',$nextEpisode) }}"
            class="player-nav">

            Episode {{ $nextEpisode->episode_number }} →

        </a>

    @else

        <div></div>

    @endif

</div>