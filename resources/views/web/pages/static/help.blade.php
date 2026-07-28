@extends('web.layouts.app')

@section('title', $title)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
    </section>

    <section class="section section-pad">
        <article class="prose">
            <h2>Bagaimana cara masuk?</h2>
            <p>Buka bot DramaVerse di Telegram, lalu tekan tombol "Buka Website".
            Anda akan diarahkan ke situs dalam keadaan sudah masuk.</p>

            <h2>Kenapa episode tertentu terkunci?</h2>
            <p>Episode bertanda VIP hanya dapat diputar oleh anggota VIP atau Premium.
            Lihat halaman Membership untuk rinciannya.</p>

            <h2>Riwayat tontonan saya hilang</h2>
            <p>Riwayat tersimpan pada akun Telegram Anda. Pastikan Anda masuk dengan
            akun Telegram yang sama.</p>

            <h2>Video tidak dapat diputar</h2>
            <p>Coba muat ulang halaman terlebih dahulu. Bila masih bermasalah,
            laporkan judul dan nomor episodenya melalui bot Telegram kami.</p>

            <h2>Masih butuh bantuan?</h2>
            <p>Kirim pesan langsung ke bot DramaVerse di Telegram. Kami membalas
            dalam 1x24 jam.</p>
        </article>
    </section>

@endsection
