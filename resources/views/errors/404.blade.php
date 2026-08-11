{{--
    404 — halaman tidak ditemukan.

    Halaman ini paling sering dibuka dari mesin pencari dan tautan lama, jadi
    tombol keduanya mengarah ke beranda untuk pengunjung. Lihat `$tujuan` di
    errors/layout.blade.php.
--}}
@extends('errors.layout')

@section('kode', 'Tidak ditemukan')
@section('judul', 'Halaman ini tidak ada di katalog')

@section('pesan')
    Tautannya mungkin sudah berubah, dramanya dihapus, atau alamatnya salah
    ketik. Coba cari dari beranda.
@endsection

@section('ilustrasi')
    {{-- Gulungan film kosong: lingkaran tengahnya berisi kaca pembesar. --}}
    <svg class="gambar" viewBox="0 0 200 176" role="img"
         aria-label="Gulungan film dengan kaca pembesar di tengahnya">
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
        <circle cx="96" cy="78" r="11" fill="none" stroke="#FFCE73" stroke-width="2.5"/>
        <path d="M104 86l8 8" stroke="#C8102E" stroke-width="3" stroke-linecap="round"/>
        <g stroke="rgba(255,178,56,.3)" stroke-width="2" stroke-linecap="round">
            <path d="M22 165h156"/>
            <path d="M34 158v14M52 158v14M70 158v14M88 158v14M106 158v14M124 158v14M142 158v14M160 158v14"/>
        </g>
    </svg>
@endsection
