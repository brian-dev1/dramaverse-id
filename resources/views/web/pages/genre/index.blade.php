@extends('web.layouts.app')

@section('title', 'Genre')
@section('description', 'Jelajahi drama Asia berdasarkan genre.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Jelajahi Genre</h1>
        <p class="page-subtitle">Pilih suasana yang sedang Anda cari.</p>
    </section>

    @if ($genres->isEmpty())
        <x-web.home.empty-state
            title="Genre belum tersedia"
            message="Admin belum menambahkan genre apa pun."
            :href="route('web.home')" action="Kembali ke Beranda" />
    @else
        <section class="section section-pad">
            <div class="pill-row">
                @foreach ($genres as $genre)
                    <a href="{{ route('web.genre.show', $genre->slug) }}" class="pill">
                        {{ $genre->name }}
                        <span class="pill-count">{{ $genre->dramas_count }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

@endsection
