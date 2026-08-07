@extends('web.layouts.app')

@section('title', $title)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
    </section>

    <section class="section section-pad">
        <article class="prose">
            <p><em>Terakhir diperbarui: {{ \App\Support\Waktu::tanggal(now()) }}</em></p>

            <h2>Data yang kami kumpulkan</h2>
            <ul>
                <li>ID, nama, dan username Telegram Anda</li>
                <li>Riwayat tontonan dan posisi terakhir pemutaran</li>
                <li>Daftar favorit dan daftar tonton</li>
                <li>Waktu akses terakhir</li>
            </ul>

            <h2>Yang tidak kami kumpulkan</h2>
            <p>Kami tidak meminta alamat email, nomor telepon, maupun kata sandi.
            Kami tidak memiliki akses ke isi percakapan Telegram Anda.</p>

            <h2>Penggunaan data</h2>
            <p>Data hanya dipakai untuk menjalankan layanan: menyinkronkan riwayat,
            memberi rekomendasi, dan memverifikasi status membership.
            Kami tidak menjual data kepada pihak ketiga.</p>

            <h2>Penghapusan data</h2>
            <p>Anda dapat meminta penghapusan akun beserta seluruh datanya melalui
            bot Telegram kami.</p>
        </article>
    </section>

@endsection
