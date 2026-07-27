<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DramaVerse</title>
</head>
<body>

<h1>DramaVerse</h1>

<p>
    <a href="{{ route('search') }}">
        Cari Drama
    </a>
</p>

<hr>

<h2>Trending</h2>

@if($trending->isEmpty())
    <p>Belum ada data.</p>
@else
    <ul>
        @foreach($trending as $drama)
            <li>
                <a href="{{ route('drama.show', $drama->slug) }}">
                    {{ $drama->title }}
                </a>
            </li>
        @endforeach
    </ul>
@endif

<hr>

<h2>Drama Terbaru</h2>

@if($latest->isEmpty())
    <p>Belum ada data.</p>
@else
    <ul>
        @foreach($latest as $drama)
            <li>
                <a href="{{ route('drama.show', $drama->slug) }}">
                    {{ $drama->title }}
                </a>
            </li>
        @endforeach
    </ul>
@endif

@if(isset($continueWatching) && $continueWatching->isNotEmpty())

<hr>

<h2>Lanjut Menonton</h2>

<ul>
    @foreach($continueWatching as $history)
        <li>
            <a href="{{ route('episode.show', $history->episode->id) }}">
                {{ $history->drama->title }}
                - Episode {{ $history->episode->episode_number }}
            </a>
        </li>
    @endforeach
</ul>

@endif

</body>
</html>