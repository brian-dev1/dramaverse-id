@props([
    'drama',
    'currentEpisode'
])

<div class="player-sidebar-card">

    <div class="player-sidebar-header">

        <h3>

            Daftar Episode

        </h3>

        <span>

            {{ $drama->episodes->count() }} Episode

        </span>

    </div>

    <div class="player-episode-list">

        @foreach($drama->episodes->sortBy('episode_number') as $episode)

            <a
                href="{{ route('episode.show',$episode) }}"
                class="player-episode-item
                {{ $episode->id == $currentEpisode->id ? 'active' : '' }}">

                <div class="episode-no">

                    {{ str_pad($episode->episode_number,2,'0',STR_PAD_LEFT) }}

                </div>

                <div class="episode-info">

                    <strong>

                        Episode {{ $episode->episode_number }}

                    </strong>

                    @if($episode->title)

                        <small>

                            {{ $episode->title }}

                        </small>

                    @endif

                </div>

                @if($episode->id == $currentEpisode->id)

                    <span class="episode-playing">

                        ▶

                    </span>

                @endif

            </a>

        @endforeach

    </div>

</div>