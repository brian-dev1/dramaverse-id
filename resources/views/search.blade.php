<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Pencarian Drama</title>

</head>

<body>

<h1>Pencarian Drama</h1>

<form
    action="{{ route('search') }}"
    method="GET">

    <input
        type="text"
        name="q"
        placeholder="Cari drama..."
        value="{{ $keyword }}"
    >

    <button type="submit">

        Cari

    </button>

</form>

<hr>

@if($keyword === '')

<p>Masukkan judul drama.</p>

@elseif($results->isEmpty())

<p>Tidak ada hasil.</p>

@else

<ul>

@foreach($results as $drama)

<li>

<a href="{{ route('drama.show',$drama->slug) }}">

{{ $drama->title }}

</a>

</li>

@endforeach

</ul>

@endif

</body>

</html>