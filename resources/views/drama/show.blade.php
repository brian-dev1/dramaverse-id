@extends('web.layouts.app')

@section('title', $drama->title)

@section('content')

<div class="drama-detail-page">

    {{-- HERO --}}
    <x-web.drama.hero :drama="$drama" />

    {{-- CONTENT --}}
    <section class="container drama-content">

        <div class="drama-main">

            <x-web.drama.info
                :drama="$drama"
            />

            <x-web.drama.episode-list
                :episodes="$drama->episodes"
            />

        </div>

        <aside class="drama-sidebar">

            <x-web.drama.recommendation
                :drama="$drama"
            />

        </aside>

    </section>

</div>

@endsection