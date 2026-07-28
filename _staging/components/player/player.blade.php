@props([
    'episode'
])

<div class="player-card">

    @if($episode->video_url)

        <video
            controls
            controlsList="nodownload"
            preload="metadata"
            class="web-video-player">

            <source
                src="{{ $episode->video_url }}">

        </video>

    @else

        <div class="player-empty">

            <div class="player-empty-icon">

                🎬

            </div>

            <h3>

                Video Belum Tersedia

            </h3>

            <p>

                Episode ini belum memiliki video.

            </p>

        </div>

    @endif

</div>