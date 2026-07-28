@props([
'episode'
])

<div class="player-info">

    <h1>

        {{ $episode->drama->title }}

    </h1>

    <h2>

        Episode {{ $episode->episode_number }}

    </h2>

    @if($episode->title)

        <p>

            {{ $episode->title }}

        </p>

    @endif

    <div class="player-meta">

        @if($episode->duration)

            <span>

                ⏱ {{ $episode->duration }}

            </span>

        @endif

        <span>

            📺 Episode {{ $episode->episode_number }}

        </span>

    </div>

</div>