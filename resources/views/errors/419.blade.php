{{--
    419 — Page Expired.

    Muncul saat token CSRF sudah kedaluwarsa: tab dibiarkan terbuka berjam-jam
    lalu formnya dikirim, atau halaman di-refresh setelah sesi mati. Bawaan
    Laravel hanya menampilkan tulisan "Page Expired" di layar putih, dan orang
    yang melihatnya wajar mengira panelnya rusak.

    Nada pesannya sengaja menenangkan lebih dulu — yang paling ditakutkan
    setelah menekan Simpan lalu melihat halaman galat adalah pekerjaannya
    hilang.
--}}
@extends('errors.layout')

@section('kode', 'Sesi berakhir')
@section('judul', 'Waktunya habis, adegan disudahi')

@section('pesan')
    Sesi Anda sudah kedaluwarsa karena tidak ada aktivitas cukup lama.
    Tidak ada data yang rusak — cukup muat ulang halaman atau masuk kembali
    untuk melanjutkan.
@endsection
