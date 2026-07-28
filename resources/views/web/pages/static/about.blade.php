@extends('web.layouts.app')

@section('title', $title)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
    </section>

    <section class="section section-pad">
        <article class="prose">
            <p>DramaVerse ID adalah platform streaming drama Asia yang bersifat privat.
            Akses hanya diberikan kepada pengguna yang masuk melalui Telegram.</p>

            <h2>Apa yang kami sediakan</h2>
            <ul>
                <li>Drama Korea, Tiongkok, Thailand, Jepang, Taiwan, dan Filipina</li>
                <li>Subtitle Bahasa Indonesia</li>
                <li>Kualitas hingga 4K untuk anggota Premium</li>
                <li>Riwayat tontonan yang tersinkronisasi antar perangkat</li>
            </ul>

            <h2>Mengapa Telegram</h2>
            <p>Tidak ada pendaftaran email dan tidak ada kata sandi yang perlu diingat.
            Identitas Telegram Anda sudah cukup, sehingga kami menyimpan data pribadi
            sesedikit mungkin.</p>
        </article>
    </section>

@endsection
