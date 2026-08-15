@extends('web.layouts.app')

@section('title', $drama->title)
@section('description', Str::limit($drama->synopsis, 155))

@section('content')

    {{-- HERO DETAIL --}}
    <section class="detail-hero {{ $drama->gradient ?? 'g1' }}"
             @if ($drama->cover_url) style="background-image:url('{{ $drama->cover_url }}')" @endif>

        <div class="detail-hero-inner section-pad">

            <div class="detail-poster {{ $drama->gradient ?? 'g1' }}">
                @if ($drama->poster_url)
                    {{-- Gambar terbesar di halaman ini dan yang menentukan kapan
                         halaman terasa selesai dimuat, jadi diberi prioritas. --}}
                    <img src="{{ $drama->poster_url }}" alt="{{ $drama->title }}"
                         fetchpriority="high" decoding="async">
                @else
                    <span class="detail-poster-title">{{ $drama->title }}</span>
                @endif
            </div>

            <div class="detail-body">

                <h1 class="hero-title">{{ $drama->title }}</h1>

                @if ($drama->original_title)
                    <p class="detail-original">{{ $drama->original_title }}</p>
                @endif

                <div class="hero-meta">
                    @if ($drama->country)
                        <span class="chip"><x-web.home.country-badge :country="$drama->country" /> {{ $drama->country->name }}</span>
                    @endif
                    <span class="chip">
                        {{ ['ongoing' => 'Sedang Tayang', 'completed' => 'Tamat', 'upcoming' => 'Akan Tayang'][$drama->status] ?? $drama->status }}
                    </span>
                    @if ($drama->total_episode)
                        <span class="chip gold">{{ $drama->total_episode }} Part</span>
                    @endif
                    @if ($drama->is_vip)
                        <span class="chip gold">VIP</span>
                    @endif
                </div>

                @if ($drama->genres->isNotEmpty())
                    <div class="pill-row detail-genres">
                        @foreach ($drama->genres as $genre)
                            <a href="{{ route('web.genre.show', $genre->slug) }}" class="pill">{{ $genre->name }}</a>
                        @endforeach
                    </div>
                @endif

                @if ($drama->synopsis)
                    <p class="hero-desc">{{ $drama->synopsis }}</p>
                @endif

                <div class="hero-actions">

                    @if ($drama->episodes->isNotEmpty())
                        @php
                            $episodePertama = $drama->episodes->first();

                            // Pintasan ke bot hanya dipasang bila videonya
                            // memang sudah ada di Telegram. Bila belum, tombol
                            // ini kembali ke perilaku lamanya — membuka halaman
                            // episode, yang sudah tahu cara mengatakan "video
                            // belum siap". Melempar orang ke bot untuk dijawab
                            // dengan penolakan adalah dead link yang kebetulan
                            // berpindah aplikasi dulu.
                            $tgEpisodePertama = $episodePertama->video?->isSyncedToTelegram()
                                ? \App\Support\TelegramDeepLink::attribute($episodePertama)
                                : '';
                        @endphp

                        <a href="{{ route('web.episode.show', $episodePertama->id) }}"
                           class="btn btn-primary" {{ $tgEpisodePertama }}>
                            <x-web.home.icon name="play" :size="15" />
                            Tonton Part {{ $episodePertama->episode_number }}
                        </a>
                    @endif

                    @auth
                        <form method="POST" action="{{ route('web.favorites.toggle', $drama->slug) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">
                                {{ $isFavorite ? 'Hapus dari Favorit' : 'Tambah ke Favorit' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('web.my-list.toggle', $drama->slug) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">
                                {{ $inMyList ? 'Hapus dari Daftar' : 'Tambah ke Daftar Saya' }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('web.membership') }}" class="btn btn-ghost">Masuk untuk Menyimpan</a>
                    @endauth

                </div>

            </div>
        </div>
    </section>

    {{-- DAFTAR EPISODE --}}
    <section class="section section-pad">

        <x-web.home.section-header
            title="Daftar Part"
            :count="$drama->episodes->count().' part'" />

        @if ($drama->episodes->isEmpty())
            <p class="page-subtitle">Part belum tersedia.</p>
        @else
            <div class="episode-list">
                @foreach ($drama->episodes as $episode)
                    {{-- Setiap baris ikut membawa pintasan, jadi di Mini App
                         satu tap dari daftar ini langsung memutar videonya.
                         Baris yang videonya belum tersinkron tetap membuka
                         halaman episode seperti biasa. --}}
                    <a href="{{ route('web.episode.show', $episode->id) }}" class="episode-item"
                       {{ $episode->video?->isSyncedToTelegram()
                            ? \App\Support\TelegramDeepLink::attribute($episode)
                            : '' }}>
                        <span class="episode-number">{{ str_pad($episode->episode_number, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="episode-title">{{ $episode->title ?: 'Part '.$episode->episode_number }}</span>
                        <span class="episode-meta">
                            @if ($episode->is_vip)<span class="chip gold">VIP</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

    </section>

    {{-- DRAMA TERKAIT --}}
    <x-web.home.grid :dramas="$related" title="Drama Serupa" />

@endsection

@section('promo')
    @guest
        <x-web.home.membership-banner />
    @endguest
@endsection
