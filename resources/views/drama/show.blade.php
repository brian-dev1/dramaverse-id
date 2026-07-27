<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>{{ $drama->title }}</title>

</head>

<body>

<h1>{{ $drama->title }}</h1>

@if($drama->poster)

<img
    src="{{ asset($drama->poster) }}"
    alt="{{ $drama->title }}"
    width="220">

@endif

@if($drama->description)

<p>

{{ $drama->description }}

</p>

@endif

<hr>

<table border="1" cellpadding="8">

<tr>

<td>Country</td>

<td>{{ optional($drama->country)->name }}</td>

</tr>

<tr>

<td>Genre</td>

<td>{{ optional($drama->genre)->name }}</td>

</tr>

<tr>

<td>Tahun</td>

<td>{{ $drama->release_year }}</td>

</tr>

<tr>

<td>Status</td>

<td>{{ $drama->status }}</td>

</tr>

<tr>

<td>Total Episode</td>

<td>{{ $drama->total_episode }}</td>

</tr>

</table>

<hr>

<h2>Daftar Episode</h2>

@if($drama->episodes->isEmpty())

<p>

Belum ada episode.

</p>

@else

<ul>

@foreach($drama->episodes->sortBy('episode_number') as $episode)

<li>

<a href="#">

Episode {{ $episode->episode_number }}

@if($episode->title)

- {{ $episode->title }}

@endif

</a>

</li>

@endforeach

</ul>

@endif

<p>

<a href="{{ route('home') }}">

← Kembali ke Homepage

</a>

</p>

</body>

</html>