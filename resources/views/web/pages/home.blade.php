@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@section('content')

    <x-web.home.hero :banners="$banners" :dramas="$trending" />

    <div class="perf" style="margin-top:32px;">
        @for ($i = 0; $i < 30; $i++)<span></span>@endfor
    </div>

    <x-web.home.continue-watching :histories="$continueWatching" />

    <x-web.home.rail
        :dramas="$trending"
        title="Trending Minggu Ini"
        count="Diperbarui setiap hari"
        variant="rank"
        :href="route('web.trending')" />

    <x-web.home.genre :genres="$genres" />

    <x-web.home.grid
        :dramas="$latest"
        title="Rilis Terbaru"
        variant="latest"
        :href="route('web.latest')" />

    <x-web.home.rail
        :dramas="$popular"
        title="Populer Minggu Ini"
        :href="route('web.popular')" />

    <x-web.home.country :countries="$countries" />

    <x-web.home.grid
        :dramas="$topRated"
        title="Rating Tertinggi"
        variant="rated"
        :href="route('web.top-rated')" />

@endsection
