@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@php
    // Beranda gaya aplikasi HP: tanpa hero, tanpa rail geser.
    // Semua koleksi digabung jadi satu daftar drama, duplikat dibuang.
    $semua = collect()
        ->concat($trending)
        ->concat($latest)
        ->concat($popular)
        ->concat($topRated)
        ->unique('id')
        ->values();
@endphp

@section('content')

    <nav class="home-chips" aria-label="Filter cepat">
        <a href="{{ route('web.home') }}" class="is-active">Semua</a>
        <a href="{{ route('web.trending') }}">Trending</a>
        <a href="{{ route('web.latest') }}">Terbaru</a>
        <a href="{{ route('web.popular') }}">Populer</a>
        <a href="{{ route('web.top-rated') }}">Rating Tertinggi</a>
        <a href="{{ route('web.genre.index') }}">Genre</a>
    </nav>

    @if ($semua->isNotEmpty())
        <section class="section section-pad">
            <div class="grid">
                @foreach ($semua as $drama)
                    <x-web.home.drama-card :drama="$drama" />
                @endforeach
            </div>
        </section>
    @else
        <x-web.home.empty-state
            title="Katalog belum diisi"
            message="Belum ada drama yang dipublikasikan. Judul akan muncul di sini begitu ditambahkan."
            :href="auth()->user()?->isAdmin() ? route('admin.drama.index') : null"
            action="Kelola Katalog" />
    @endif

@endsection
