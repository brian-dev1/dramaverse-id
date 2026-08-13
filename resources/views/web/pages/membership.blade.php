@extends('web.layouts.app')

@section('title', 'Upgrade ke VIP')
@section('description', 'Paket VIP DramaVerse ID — akses semua film dan series tanpa batas.')

@section('content')

    {{--
        Etalase harga.

        Tombol setiap paket adalah tautan t.me biasa. Di dalam Mini App,
        JavaScript pada partials/miniapp mencegatnya dan memanggil
        openTelegramLink() — di webview Telegram, <a href="t.me/..."> yang
        tidak dicegat memang diam saja. Di peramban biasa tautannya bekerja
        seperti tautan mana pun. Satu HTML untuk kedua tempat.
    --}}

    <section class="vip-head">
        <span class="vip-crest" aria-hidden="true">
            <x-web.home.icon name="crown" :size="30" />
        </span>

        <h1 class="vip-title">Upgrade ke VIP</h1>

        <p class="vip-sub">Bebas akses semua film tanpa batas</p>

        @if ($status && $status['status'] === 'premium')
            <p class="vip-status is-active">
                VIP aktif{{ $status['plan'] ? ' — '.$status['plan'] : '' }}.
                Membeli lagi menambah masa aktif, bukan menggantinya.
            </p>
        @elseif ($status && $status['status'] === 'expired')
            <p class="vip-status is-expired">
                Masa VIP Anda sudah habis. Perpanjang untuk membuka episode premium lagi.
            </p>
        @endif
    </section>

    @if ($wilayah->count() > 1)
        {{--
            Pilihan wilayah hanya muncul kalau memang ada dua. Pertanyaan yang
            jawabannya cuma satu bukan pertanyaan — dan pemasangan yang hanya
            melayani Indonesia tidak perlu satu ketukan tambahan.
        --}}
        <div class="vip-region-wrap">

            <p class="vip-region-lead">
                Harga berbeda tiap wilayah. Pilih sesuai alat bayar yang Anda pegang,
                bukan tempat Anda tinggal.
            </p>

            <div class="vip-region" role="tablist" aria-label="Wilayah pembayaran">
                @foreach ($wilayah as $r)
                    <a href="{{ route('web.membership', ['wilayah' => $r->value]) }}"
                       class="{{ $terpilih === $r ? 'active' : '' }}"
                       role="tab" aria-selected="{{ $terpilih === $r ? 'true' : 'false' }}">
                        <span aria-hidden="true">{{ $r->bendera() }}</span> {{ $r->label() }}
                    </a>
                @endforeach
            </div>

            @if ($terpilih)
                <p class="vip-region-note">{{ $terpilih->keterangan() }}</p>
            @endif

            {{--
                Peringatan penyalahgunaan wilayah.

                Ditempatkan tepat di bawah pemilihnya, bukan di kaki halaman:
                ia hanya berguna kalau terbaca SEBELUM orang menekan salah satu
                tab. Peringatan yang datang setelah pilihan diambil cuma
                menjelaskan hukuman, bukan mencegah pelanggarannya.
            --}}
            <p class="vip-region-warn">
                <strong>Pilih sesuai negara Anda saat ini.</strong>
                Daftar harga tiap wilayah menyesuaikan metode pembayaran yang berlaku di
                sana. Memakai daftar harga wilayah lain sementara Anda membayar dari
                negara berbeda dapat berakibat <strong>pemblokiran akun oleh admin</strong>.
            </p>
        </div>
    @endif

    <section class="vip-section">

        <div class="vip-section-head">
            <h2>Pilih Paket</h2>
            <span>Tidak berlangganan otomatis.</span>
        </div>

        @if ($plans->isEmpty())

            <x-web.home.empty-state
                title="Paket belum tersedia"
                message="Admin belum menambahkan paket, atau metode pembayarannya belum diatur."
                :href="route('web.home')" action="Kembali ke Beranda" />

        @else

            <ul class="vip-plans">
                @foreach ($plans as $plan)
                    <li class="vip-plan @if ($plan['badge']) has-badge @endif">

                        @if ($plan['badge'])
                            <span class="vip-badge">{{ $plan['badge'] }}</span>
                        @endif

                        {{--
                            Tanpa tautan, kartunya tetap dirender sebagai
                            <div> — bukan <a> yang tidak menuju ke mana pun.
                            Terjadi hanya bila TELEGRAM_BOT_USERNAME kosong.
                        --}}
                        @if ($plan['tautan'])
                            <a class="vip-plan-body" href="{{ $plan['tautan'] }}"
                               rel="noopener"
                               aria-label="Beli {{ $plan['nama'] }} seharga {{ $plan['harga'] }}">
                        @else
                            <div class="vip-plan-body">
                        @endif

                            <span class="vip-plan-mark" aria-hidden="true">
                                <x-web.home.icon name="crown" :size="18" />
                            </span>

                            <span class="vip-plan-main">
                                <span class="vip-plan-name">{{ $plan['nama'] }}</span>
                                <span class="vip-plan-rate">≈ {{ $plan['harian'] }}/hari</span>
                            </span>

                            <span class="vip-plan-side">
                                <span class="vip-plan-price">{{ $plan['harga'] }}</span>
                                <span class="vip-plan-days">{{ $plan['durasi'] }} hari</span>
                            </span>

                            <span class="vip-plan-go" aria-hidden="true">
                                <x-web.home.icon name="arrow-right" :size="16" />
                            </span>

                        @if ($plan['tautan'])
                            </a>
                        @else
                            </div>
                        @endif

                    </li>
                @endforeach
            </ul>

            <p class="vip-note">
                Pembayaran diproses lewat Telegram seperti biasa. Menekan paket
                membuka chat bot, dan tagihan beserta QRIS-nya dikirim di sana.
            </p>

        @endif

    </section>

    <section class="vip-section">

        <div class="vip-section-head">
            <h2>Kenapa VIP?</h2>
        </div>

        <ul class="vip-perks">
            @foreach ([
                ['film',     'Akses Semua Film & Series', 'Ribuan judul, tonton tanpa batas'],
                ['play',     'Tanpa Buffering',           'Kualitas HD, langsung diputar di Telegram'],
                ['no-ads',   'Tanpa Iklan',               'Tidak ada jeda di tengah episode'],
                ['download', 'Simpan & Tonton Ulang',     'Video tetap ada di chat Anda'],
            ] as [$ikon, $judul, $teks])
                <li class="vip-perk">
                    <span class="vip-perk-icon" aria-hidden="true">
                        <x-web.home.icon :name="$ikon" :size="18" />
                    </span>
                    <span>
                        <strong>{{ $judul }}</strong>
                        <small>{{ $teks }}</small>
                    </span>
                </li>
            @endforeach
        </ul>

    </section>

    @auth
        @if ($subscriptions->isNotEmpty())
            <section class="vip-section">

                <div class="vip-section-head">
                    <h2>Riwayat Pembelian</h2>
                </div>

                <table class="data-table">
                    <thead>
                        <tr><th>Paket</th><th>Harga</th><th>Mulai</th><th>Berakhir</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $sub)
                            <tr>
                                <td>{{ $sub->plan?->name ?? '—' }}</td>
                                {{-- Langganan menyimpan harga saat dibeli; mata uangnya mengikuti paketnya. --}}
                                <td>{{ \App\Support\Uang::format($sub->price, $sub->plan?->currency) }}</td>
                                <td>{{ $sub->started_at?->ringkas() ?? '—' }}</td>
                                <td>{{ $sub->expired_at?->ringkas() ?? '—' }}</td>
                                <td>{{ ucfirst($sub->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

            </section>
        @endif
    @endauth

@endsection
