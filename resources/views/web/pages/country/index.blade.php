@extends('web.layouts.app')

@section('title', 'Negara')
@section('description', 'Jelajahi drama berdasarkan negara asal.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Jelajahi Negara</h1>
        <p class="page-subtitle">Dari Seoul sampai Bangkok.</p>
    </section>

    @if ($countries->isEmpty())
        <x-web.home.empty-state
            title="Negara belum tersedia"
            message="Admin belum menambahkan data negara."
            :href="route('web.home')" action="Kembali ke Beranda" />
    @else
        <section class="section section-pad">
            <div class="pill-row">
                @foreach ($countries as $country)
                    <a href="{{ route('web.country.show', $country->slug) }}" class="pill">
                        <x-web.home.country-badge :country="$country" /> {{ $country->name }}
                        <span class="pill-count">{{ $country->dramas_count }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

@endsection
