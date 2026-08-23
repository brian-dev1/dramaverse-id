@extends('web.layouts.app')

@section('title', 'Beranda')
@section('description', 'Streaming drama Korea, Tiongkok, Thailand, dan Jepang dengan subtitle Bahasa Indonesia.')

@php
    // Katalog dianggap kosong bila tidak ada satu pun drama terbit.
    $catalogEmpty = $trending->isEmpty()
        && $latest->isEmpty()
        && $popular->isEmpty();

    /*
    | Halaman 2 dan seterusnya bukan lagi beranda — orang sudah memutuskan
    | untuk menelusuri katalog. Rail dan baris chip disembunyikan di sana
    | supaya yang tersisa hanya daftarnya sendiri; membawa "Lanjutkan
    | Menonton" dan "Trending" ikut ke setiap halaman berarti menyuruh orang
    | menggulir melewati barang yang sama berulang-ulang hanya untuk sampai
    | ke tempat mereka tadi berhenti.
    */
    $berandaPenuh = $latest->onFirstPage();
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

    @if ($berandaPenuh)
        {{-- Penyaring cepat, tepat di bawah kolom cari. --}}
        <x-web.home.genre :genres="$genres" />
        <x-web.home.country :countries="$countries" />
    @endif

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

    @if ($berandaPenuh)
        <x-web.home.continue-watching :histories="$continueWatching" />

        {{-- Rail teratas yang pasti ada isinya: posternya jadi gambar pertama
             yang dilihat pengguna, jadi tiga di antaranya tidak ditunda. --}}
        <x-web.home.rail
            :dramas="$trending"
            title="Trending Minggu Ini"
            variant="rank"
            :priority="true"
            :href="route('web.trending')" />

        <x-web.home.rail
            :dramas="$popular"
            title="Populer Minggu Ini"
            :href="route('web.popular')" />
    @endif

    {{--
        Daftar utama. Berhalaman, dan menjadi SATU-SATUNYA isi halaman mulai
        dari halaman kedua. Tautan "Lihat Semua" dilepas: tombol angka dan
        Next di bawah daftar sudah membawa ke tempat yang sama, dan dua jalan
        menuju hal yang sama di satu layar hanya membuat orang menimbang-nimbang
        mana yang benar.
    --}}
    <x-web.home.grid
        :dramas="$latest"
        title="Rilis Terbaru"
        {{-- Label "BARU" hanya sah di halaman pertama. Di halaman kelima yang
             tampil adalah judul-judul lama, dan menempeli semuanya dengan
             lencana BARU membuat lencananya berhenti berarti apa pun. --}}
        :variant="$berandaPenuh ? 'latest' : 'default'"
        :paginator="$latest" />

    @if ($catalogEmpty)
        <x-web.home.empty-state
            title="Katalog belum diisi"
            message="Belum ada drama yang dipublikasikan. Judul akan muncul di sini begitu ditambahkan."
            :href="auth()->user()?->isAdmin() ? route('admin.drama.index') : null"
            action="Kelola Katalog" />
    @endif

@endsection

