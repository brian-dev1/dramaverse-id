@extends('web.layouts.app')

@section('title', 'Drama '.$country->name)
@section('description', 'Drama asal '.$country->name.' dengan subtitle Bahasa Indonesia.')

@section('content')

    <section class="page-head section-pad">
        <a href="{{ route('web.country.index') }}" class="see-all"><x-web.home.icon name="arrow-left" :size="13" /> Semua Negara</a>
        <h1 class="page-title"><x-web.home.country-badge :country="$country" /> {{ $country->name }}</h1>
    </section>

    @if ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Belum ada drama dari {{ $country->name }}"
            message="Coba jelajahi negara lain."
            :href="route('web.country.index')" action="Lihat Negara Lain" />
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
