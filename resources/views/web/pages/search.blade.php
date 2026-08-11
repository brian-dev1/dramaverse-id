@extends('web.layouts.app')

@section('title', 'Pencarian')
@section('description', 'Cari drama Asia berdasarkan judul, genre, dan negara.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Cari Drama</h1>
        <p class="page-subtitle">Saring berdasarkan genre, negara, dan status.</p>
    </section>

    <section class="section section-pad">

        {{--
            Form tetap berupa form sungguhan yang bisa dikirim.

            Pencarian langsung ditambahkan di atasnya sebagai lapisan, bukan
            sebagai pengganti: tanpa JavaScript — atau selama skripnya belum
            selesai dimuat — menekan Enter tetap membawa ke halaman hasil
            seperti sebelumnya. Yang berubah hanya bahwa menunggu Enter tidak
            lagi diperlukan.
        --}}
        <form method="GET" action="{{ route('web.search.result') }}" class="search-form"
              data-live-search
              data-endpoint="{{ route('api.v1.search') }}"
              data-request-url="{{ route('web.request.index') }}">

            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Judul drama..." class="search-input" autofocus
                   autocomplete="off" data-live-input>

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

            <select name="status" class="search-select">
                <option value="">Semua Status</option>
                @foreach (['ongoing' => 'Sedang Tayang', 'completed' => 'Tamat', 'upcoming' => 'Akan Tayang'] as $val => $label)
                    <option value="{{ $val }}" @selected(request('status') === $val)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="sort" class="search-select">
                @foreach (['' => 'Terbaru', 'popular' => 'Populer', 'oldest' => 'Terlama'] as $val => $label)
                    <option value="{{ $val }}" @selected(request('sort') === $val)>{{ $label }}</option>
                @endforeach
            </select>

            <label class="search-check">
                <input type="checkbox" name="vip" value="1" @checked(request()->boolean('vip'))> VIP saja
            </label>

            <button type="submit" class="btn btn-primary">Cari</button>

        </form>

    </section>

    {{--
        Wadah hasil pencarian langsung.

        Selama kosong, bagian di bawahnya — hasil yang dirender server —
        tetap terlihat seperti biasa. Begitu pengguna mengetik, skrip
        menyembunyikan yang bawah dan mengisi yang ini.
    --}}
    <section class="section section-pad" data-live-wrap hidden>

        <div class="live-state" data-live-loading>
            <p class="live-dots"><span></span><span></span><span></span></p>
            <p class="live-hint">Mencari…</p>
        </div>

        <div class="live-state" data-live-empty>
            <p><strong>Drama tidak tersedia?</strong></p>
            <p>Tidak ada yang cocok dengan <span data-live-keyword></span>. Kirim judulnya
               ke kami — Anda akan diberi tahu lewat Telegram begitu dramanya ada.</p>

            {{-- Kata kuncinya dibawa ke form request lewat ?q= supaya kolom
                 judulnya sudah terisi. Menyuruh orang mengetik ulang judul
                 yang baru saja ia ketik adalah cara termudah membuatnya
                 mengurungkan niat. --}}
            <a href="{{ route('web.request.index') }}" class="btn btn-primary" data-live-request>
                <x-web.home.icon name="send" :size="15" />
                Request Drama Ini
            </a>
        </div>

        <div class="live-state" data-live-error>
            <p>Pencarian sedang bermasalah. Tekan Enter untuk mencari dengan cara biasa.</p>
        </div>

        <div data-live-results></div>
    </section>

    <div data-server-results>
    @if ($dramas === null)
        <x-web.home.empty-state
            title="Mulai mencari"
            message="Ketik judul drama atau pilih filter di atas."
            :href="route('web.trending')" action="Lihat Trending" />
    @elseif ($dramas->isEmpty())
        {{-- Hasil kosong mengarah ke request, bukan cuma "ulangi pencarian"
             yang tidak menyelesaikan apa pun.

             Tamu diarahkan ke beranda: permintaan butuh pemilik, karena
             janjinya adalah "Anda akan diberi tahu" — dan itu tidak bisa
             ditepati kepada orang yang tidak dikenali. --}}
        @auth
            <x-web.home.empty-state
                title="Drama tidak tersedia?"
                message="Tidak ada drama yang cocok. Kirim judulnya ke kami — Anda akan diberi tahu begitu dramanya ada."
                :href="route('web.request.index', ['q' => $keyword])" action="Request Drama" />
        @else
            <x-web.home.empty-state
                title="Drama tidak tersedia?"
                message="Tidak ada drama yang cocok. Masuk lewat Telegram untuk meminta drama ini, dan kami beri tahu begitu tersedia."
                :href="route('web.home')" action="Kembali ke Beranda" />
        @endauth
    @else
        <x-web.home.grid :dramas="$dramas" :title="'Hasil: '.$dramas->total().' drama'" />

        <div class="section-pad pagination-wrap">{{ $dramas->links() }}</div>
    @endif
    </div>

@endsection
