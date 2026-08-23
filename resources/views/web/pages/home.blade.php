@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@php
    // Katalog dianggap kosong bila tidak ada satu pun drama terbit.
    $catalogEmpty = $trending->isEmpty()
        && $latest->isEmpty()
        && $popular->isEmpty();
@endphp

@section('content')

    {{--
        Total judul, rata kanan tepat di bawah kolom cari.

        Diletakkan di sini, bukan di dalam navbar, karena angkanya hanya
        dikirim ke tampilan beranda — memasangnya di navbar berarti setiap
        halaman lain harus ikut menghitungnya, atau menampilkan kosong.
    --}}
    @isset($totalDrama)
        @if ($totalDrama > 0)
            <p class="dv-total"><b>{{ number_format($totalDrama, 0, ',', '.') }}</b> judul</p>
        @endif
    @endisset

    {{--
        Tamu yang menekan Riwayat, Profil, atau Favorit dilempar ke sini oleh
        `redirectGuestsTo()` dengan `?masuk=1`. Sampai sekarang parameter itu
        tidak dibaca siapa pun: yang terjadi hanyalah halaman berganti kembali
        ke beranda, tanpa satu kata pun tentang kenapa. Dari sisi pengguna itu
        tidak bisa dibedakan dari tombol yang rusak.

        Yang menutup lubangnya bukan pesannya, melainkan tombol di bawahnya —
        pengguna biasa tidak punya kata sandi, jadi memberitahunya "silakan
        masuk" tanpa menunjukkan caranya sama saja dengan tidak memberi tahu.
    --}}
    @guest
        @if (request()->boolean('masuk'))
            @php $masukUrl = \App\Support\TelegramDeepLink::login(); @endphp

            <div class="login-prompt" role="status">
                <x-web.home.icon name="user" :size="18" />
                <div>
                    <strong>Masuk dulu untuk membuka halaman itu.</strong>
                    <p>
                        Riwayat, Favorit, dan Profil terikat pada akun Anda. DramaVerse
                        tidak memakai kata sandi — Telegram yang membuktikan identitas
                        Anda, lalu bot mengirim tautan masuk sekali pakai.
                    </p>
                </div>

                @if ($masukUrl)
                    <a href="{{ $masukUrl }}" class="btn btn-primary btn-sm"
                       target="_blank" rel="noopener noreferrer">
                        Masuk lewat Telegram
                    </a>
                @endif
            </div>
        @endif
    @endguest

    <x-web.home.continue-watching :histories="$continueWatching" />

    {{-- Rail teratas yang pasti ada isinya: posternya jadi gambar pertama yang
         dilihat pengguna, jadi tiga di antaranya tidak ditunda. --}}
    <x-web.home.rail
        :dramas="$trending"
        title="Trending Minggu Ini"
        variant="rank"
        :priority="true"
        :href="route('web.trending')" />

    <x-web.home.grid
        :dramas="$latest"
        title="Rilis Terbaru"
        variant="latest"
        :href="route('web.latest')" />

    <x-web.home.rail
        :dramas="$popular"
        title="Populer Minggu Ini"
        :href="route('web.popular')" />

    {{-- Taksonomi tetap ditampilkan walau katalog kosong: keduanya data nyata. --}}
    <x-web.home.genre :genres="$genres" />
    <x-web.home.country :countries="$countries" />

    @if ($catalogEmpty)
        <x-web.home.empty-state
            title="Katalog belum diisi"
            message="Belum ada drama yang dipublikasikan. Judul akan muncul di sini begitu ditambahkan."
            :href="auth()->user()?->isAdmin() ? route('admin.drama.index') : null"
            action="Kelola Katalog" />
    @endif

@endsection

