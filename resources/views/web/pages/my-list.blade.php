@extends('web.layouts.app')

@section('title', 'Daftar Saya')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Daftar Saya</h1>
        <p class="page-subtitle">Drama yang ingin Anda tonton nanti.</p>
    </section>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Daftar masih kosong"
            message="Tambahkan drama ke daftar agar mudah ditemukan nanti."
            :href="route('web.latest')" action="Lihat Rilis Terbaru" />
    @else
        <x-web.home.grid :dramas="$dramas" />
        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection
