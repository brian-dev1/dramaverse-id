@extends('web.layouts.app')

@section('title', 'Genre '.$genre->name)
@section('description', 'Drama Asia bergenre '.$genre->name.' dengan subtitle Bahasa Indonesia.')

@section('content')

    <section class="page-head section-pad">
        <a href="{{ route('web.genre.index') }}" class="see-all">&larr; Semua Genre</a>
        <h1 class="page-title">{{ $genre->name }}</h1>
        @if ($genre->description)
            <p class="page-subtitle">{{ $genre->description }}</p>
        @endif
    </section>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Belum ada drama bergenre {{ $genre->name }}"
            message="Coba jelajahi genre lain."
            :href="route('web.genre.index')" action="Lihat Genre Lain" />
    @else
        <x-web.home.grid :dramas="$dramas" />

        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection
