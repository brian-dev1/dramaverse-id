@extends('web.layouts.app')

@section('title', 'DramaVerse ID')

@section('content')

    {{-- HERO --}}
    <x-web.hero />

    {{-- PERFORATION DIVIDER --}}
    <div class="perf" style="margin-top:32px;">
        @for($i = 0; $i < 30; $i++)
            <span></span>
        @endfor
    </div>

    {{-- CONTINUE WATCHING --}}
    <x-web.continue-watching />

    {{-- TRENDING --}}
    <x-web.trending />

    {{-- GENRE --}}
    <x-web.genre />

    {{-- LATEST RELEASE --}}
    <x-web.latest-release />

    {{-- POPULAR --}}
    <x-web.popular />

    {{-- COUNTRY --}}
    <x-web.country />

    {{-- TOP RATED --}}
    <x-web.top-rated />

    {{-- MEMBERSHIP --}}
    <x-web.membership-banner />

@endsection