{{--
    500 — galat server.

    Berbeda dari 419, halaman ini menandakan ada yang benar-benar rusak.
    Karena itu ia menampilkan ID galat: satu penanda pendek yang bisa
    disebutkan pengguna saat melapor dan dicari di log oleh yang memperbaiki.
    Tanpa itu, laporan yang masuk selalu berbunyi "tadi error" dan tidak ada
    cara mencocokkannya dengan baris mana pun di storage/logs.

    Isi galatnya sendiri TIDAK ditampilkan. Pesan exception kerap memuat query
    SQL, jalur berkas, atau potongan konfigurasi.
--}}
@extends('errors.layout')

@section('kode', 'Galat server')
@section('judul', 'Ada yang tersendat di belakang layar')

@section('pesan')
    Kesalahan ini terjadi di sisi kami, bukan karena yang Anda lakukan.
    Catatannya sudah tersimpan otomatis. Coba muat ulang sebentar lagi.
@endsection

@section('ilustrasi')
    {{-- Gulungan film dengan pitanya terputus — pembeda visual dari halaman
         419 yang gambarnya utuh. --}}
    <svg class="gambar" viewBox="0 0 200 176" role="img"
         aria-label="Gulungan film dengan pita yang terputus">
        <circle cx="100" cy="82" r="72" fill="none" stroke="#FFB238" stroke-width="2.5"/>
        <circle cx="100" cy="82" r="63" fill="none" stroke="rgba(255,178,56,.35)" stroke-width="1.5"/>
        <g fill="none" stroke="rgba(255,178,56,.55)" stroke-width="2">
            <circle cx="100" cy="36" r="11"/>
            <circle cx="144" cy="68" r="11"/>
            <circle cx="127" cy="120" r="11"/>
            <circle cx="73" cy="120" r="11"/>
            <circle cx="56" cy="68" r="11"/>
        </g>
        <circle cx="100" cy="82" r="30" fill="#1F120A" stroke="#FFB238" stroke-width="2"/>
        <path d="M92 74l16 16M108 74l-16 16" stroke="#C8102E" stroke-width="3.5" stroke-linecap="round"/>
        <g stroke="rgba(255,178,56,.3)" stroke-width="2" stroke-linecap="round">
            <path d="M22 165h62"/>
            <path d="M116 165h62"/>
            <path d="M34 158v14M52 158v14M70 158v14M130 158v14M148 158v14M166 158v14"/>
        </g>
    </svg>
@endsection

@section('tambahan')
    @php
        // ID galat diambil dari header X-Request-Id bila ada di belakang
        // proxy, dan bila tidak, dari waktu kejadian. Keduanya cukup untuk
        // mempersempit pencarian di storage/logs/laravel.log.
        $tiket = request()->header('X-Request-Id')
            ?: strtoupper(substr(md5(microtime()), 0, 6)).'-'.now()->format('dHi');
    @endphp

    <p class="tiket">Kode kejadian: <b>{{ $tiket }}</b></p>
@endsection
