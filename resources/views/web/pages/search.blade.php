@extends('web.layouts.app')

@section('title', 'Pencarian')
@section('description', 'Cari drama Asia berdasarkan judul, genre, negara, dan tahun.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Cari Drama</h1>
        <p class="page-subtitle">Saring berdasarkan genre, negara, tahun, dan status.</p>
    </section>

    <section class="section section-pad">

        <form method="GET" action="{{ route('web.search.result') }}" class="search-form">

            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Judul drama..." class="search-input" autofocus>

            <select name="genre" class="search-select">
                <option value="">Semua Genre</option>
                @foreach ($genres as $genre)
                    <option value="{{ $genre->slug }}" @selected(request('genre') === $genre->slug)>
                        {{ $genre->name }}
                    </option>
                @endforeach
            </select>

            <select name="country" class="search-select">
                <option value="">Semua Negara</option>
                @foreach ($countries as $country)
                    <option value="{{ $country->slug }}" @selected(request('country') === $country->slug)>
                        {{ $country->name }}
                    </option>
                @endforeach
            </select>

            <select name="year" class="search-select">
                <option value="">Semua Tahun</option>
                @for ($y = (int) date('Y'); $y >= 2010; $y--)
                    <option value="{{ $y }}" @selected((int) request('year') === $y)>{{ $y }}</option>
                @endfor
            </select>

            <select name="status" class="search-select">
                <option value="">Semua Status</option>
                @foreach (['ongoing' => 'Sedang Tayang', 'completed' => 'Tamat', 'upcoming' => 'Akan Tayang'] as $val => $label)
                    <option value="{{ $val }}" @selected(request('status') === $val)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="sort" class="search-select">
                @foreach (['' => 'Terbaru', 'rating' => 'Rating', 'popular' => 'Populer', 'oldest' => 'Terlama'] as $val => $label)
                    <option value="{{ $val }}" @selected(request('sort') === $val)>{{ $label }}</option>
                @endforeach
            </select>

            <label class="search-check">
                <input type="checkbox" name="vip" value="1" @checked(request()->boolean('vip'))> VIP saja
            </label>

            <button type="submit" class="btn btn-primary">Cari</button>

        </form>

    </section>

    @if ($dramas === null)
        <x-web.home.empty-state
            title="Mulai mencari"
            message="Ketik judul drama atau pilih filter di atas."
            :href="route('web.trending')" action="Lihat Trending" />
    @elseif ($dramas->isEmpty())
        <x-web.home.empty-state
            title="Tidak ada hasil"
            message="Tidak ada drama yang cocok dengan pencarian Anda."
            :href="route('web.search')" action="Ulangi Pencarian" />
    @else
        <x-web.home.grid :dramas="$dramas" :title="'Hasil: '.$dramas->total().' drama'" />

        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif

@endsection
