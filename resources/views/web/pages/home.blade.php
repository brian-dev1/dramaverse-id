@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@section('content')

    <nav class="home-chips" aria-label="Filter cepat">
        <a href="{{ route('web.home') }}" class="is-active">Semua</a>
        <a href="{{ route('web.trending') }}">Trending</a>
        <a href="{{ route('web.latest') }}">Terbaru</a>
        <a href="{{ route('web.popular') }}">Populer</a>
        <a href="{{ route('web.top-rated') }}">Rating Tertinggi</a>
        <a href="{{ route('web.genre.index') }}">Genre</a>
    </nav>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Katalog belum diisi"
            message="Belum ada drama yang dipublikasikan. Judul akan muncul di sini begitu ditambahkan."
            :href="auth()->user()?->isAdmin() ? route('admin.drama.index') : null"
            action="Kelola Katalog" />
    @else
        <section class="section section-pad">
            <div class="grid">
                @foreach ($dramas as $drama)
                    <x-web.home.drama-card :drama="$drama" />
                @endforeach
            </div>
        </section>

        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection
