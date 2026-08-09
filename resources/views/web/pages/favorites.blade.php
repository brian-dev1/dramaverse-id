@extends('web.layouts.app')

@section('title', 'Favorit')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Favorit Saya</h1>
        <p class="page-subtitle">Drama yang Anda tandai sebagai favorit.</p>
    </section>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Belum ada favorit"
            message="Tekan tombol favorit pada halaman drama untuk menyimpannya di sini."
            :href="route('web.popular')" action="Lihat Yang Populer" />
    @else
        <x-web.home.grid :dramas="$dramas" />
        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection
