@extends('web.layouts.app')

@section('title', $title)
@section('description', $subtitle)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
        <p class="page-subtitle">{{ $subtitle }}</p>
    </section>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Belum ada drama di sini"
            message="Katalog untuk kategori ini masih kosong."
            :href="route('web.home')"
            action="Kembali ke Beranda" />
    @else
        <x-web.home.grid :dramas="$dramas" />

        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection

@section('promo')
    @guest
        <x-web.home.membership-banner />
    @endguest
@endsection
