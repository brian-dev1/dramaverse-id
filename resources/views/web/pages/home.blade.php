@extends('web.layouts.app')

@section('title', 'DramaVerse ID')

@section('content')

    {{-- HERO --}}
    <x-web.home.hero />

    {{-- PERFORATION DIVIDER --}}
    <div class="perf" style="margin-top:32px;">
        @for($i = 0; $i < 30; $i++)
            <span></span>
        @endfor
    </div>

    {{-- CONTINUE WATCHING --}}
    <x-web.home.continue-watching />

    {{-- TRENDING --}}
    <x-web.home.trending />

    {{-- GENRE --}}
    <x-web.home.genre />

    {{-- LATEST RELEASE --}}
    <x-web.home.latest-release />

    {{-- POPULAR --}}
    <x-web.home.popular />

    {{-- COUNTRY --}}
    <x-web.home.country />

    {{-- TOP RATED --}}
    <x-web.home.top-rated />

    {{-- MEMBERSHIP --}}
    <x-web.home.membership-banner />

@endsection