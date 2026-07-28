@extends('web.layouts.app')

@section('title', 'Pencarian Drama')

@section('content')

<section class="search-page">

    <div class="container">

        <div class="search-header">

            <h1>

                Cari Drama

            </h1>

            <p>

                Temukan drama favoritmu dengan cepat.

            </p>

        </div>

        <form
            action="{{ route('search') }}"
            method="GET"
            class="search-box">

            <input
                type="text"
                name="q"
                placeholder="Cari judul drama..."
                value="{{ $keyword }}">

            <button
                type="submit">

                Cari

            </button>

        </form>

        @if($keyword=='')

            <div class="search-empty">

                <h3>

                    Mulai Pencarian

                </h3>

                <p>

                    Ketik judul drama yang ingin kamu cari.

                </p>

            </div>

        @elseif($results->isEmpty())

            <div class="search-empty">

                <h3>

                    Drama Tidak Ditemukan

                </h3>

                <p>

                    Coba gunakan kata kunci lain.

                </p>

            </div>

        @else

            <div class="drama-grid">

                @foreach($results as $drama)

                    <x-web.home.drama-card
                        :drama="$drama" />

                @endforeach

            </div>

        @endif

    </div>

</section>

@endsection