@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@php
    // Katalog dianggap kosong bila tidak ada satu pun drama terbit.
    $catalogEmpty = $trending->isEmpty()
        && $latest->isEmpty()
        && $popular->isEmpty()
        && $topRated->isEmpty();
@endphp

@section('content')

    <x-web.home.continue-watching :histories="$continueWatching" />

    <x-web.home.rail
        :dramas="$trending"
        title="Trending Minggu Ini"
        variant="rank"
        :href="route('web.trending')" />

    <x-web.home.grid
        :dramas="$latest"
        title="Rilis Terbaru"
        variant="latest"
        :href="route('web.latest')" />

    <x-web.home.rail
        :dramas="$popular"
        title="Populer Minggu Ini"
        :href="route('web.popular')" />

    <x-web.home.grid
        :dramas="$topRated"
        title="Rating Tertinggi"
        variant="rated"
        :href="route('web.top-rated')" />

    {{-- Taksonomi tetap ditampilkan walau katalog kosong: keduanya data nyata. --}}
    <x-web.home.genre :genres="$genres" />
    <x-web.home.country :countries="$countries" />

    @if ($catalogEmpty)
        <x-web.home.empty-state
            title="Katalog belum diisi"
            message="Belum ada drama yang dipublikasikan. Judul akan muncul di sini begitu ditambahkan."
            :href="auth()->user()?->isAdmin() ? route('admin.drama.index') : null"
            action="Kelola Katalog" />
    @endif

@endsection

