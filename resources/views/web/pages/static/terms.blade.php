@extends('web.layouts.app')

@section('title', $title)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
    </section>

    <section class="section section-pad">
        <article class="prose">
            <p><em>Terakhir diperbarui: {{ now()->translatedFormat("d F Y") }}</em></p>

            <h2>Penerimaan ketentuan</h2>
            <p>Dengan mengakses DramaVerse ID, Anda menyetujui ketentuan di halaman ini.</p>

            <h2>Sifat layanan</h2>
            <p>DramaVerse ID adalah layanan privat. Akses diberikan berdasarkan undangan
            melalui Telegram dan dapat dicabut sewaktu-waktu.</p>

            <h2>Akun</h2>
            <ul>
                <li>Satu akun Telegram untuk satu orang</li>
                <li>Dilarang membagikan akses akun kepada pihak lain</li>
                <li>Dilarang mengunduh ulang dan menyebarkan konten</li>
            </ul>

            <h2>Membership</h2>
            <p>Pembayaran membership bersifat final. Masa aktif dihitung sejak
            pembayaran dikonfirmasi.</p>

            <h2>Penangguhan</h2>
            <p>Kami dapat menangguhkan akun yang melanggar ketentuan ini tanpa
            pemberitahuan sebelumnya.</p>
        </article>
    </section>

@endsection
