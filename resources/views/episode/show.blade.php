<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>

        {{ $episode->drama->title }}

    </title>

</head>

<body>

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

@if($episode->thumbnail)

<img
    src="{{ asset($episode->thumbnail) }}"
    width="300"
    alt="Thumbnail">

@endif

@if($episode->duration)

<p>

Durasi :
{{ $episode->duration }}

</p>

@endif

<hr>

@if($episode->video_url)

<video
    width="900"
    controls>

    <source
        src="{{ $episode->video_url }}">

</video>

@else

<p>

Video belum tersedia.

</p>

@endif

<hr>

@if($previousEpisode)

<a
href="{{ route('episode.show',$previousEpisode) }}">

⬅ Episode Sebelumnya

</a>

@endif

@if($nextEpisode)

|

<a
href="{{ route('episode.show',$nextEpisode) }}">

Episode Berikutnya ➜

</a>

@endif

<br><br>

<a
href="{{ route('drama.show',$episode->drama->slug) }}">

← Kembali

</a>

</body>

</html>