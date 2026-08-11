@extends('web.layouts.app')

@section('title', 'Membership')
@section('description', 'Paket VIP dan Premium DramaVerse ID.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Pilih Paket Anda</h1>
        <p class="page-subtitle">Akses penuh katalog, tanpa iklan, kualitas hingga 4K.</p>
    </section>

    <section class="section section-pad">
        @if ($plans->isEmpty())
            <x-web.home.empty-state
                title="Paket belum tersedia"
                message="Admin belum menambahkan paket membership."
                :href="route('web.home')" action="Kembali ke Beranda" />
        @else
            <div class="plan-grid">
                @foreach ($plans as $plan)
                    <article class="plan-card">
                        <h3>{{ $plan->name }}</h3>
                        <p class="plan-price">{{ $plan->hargaTampil() }}</p>
                        <p class="plan-duration">{{ $plan->duration }} hari</p>

                        @if ($plan->description)
                            <p class="plan-desc">{{ $plan->description }}</p>
                        @endif

                        {{--
                            Berlangganan dipindahkan sepenuhnya ke bot Telegram.

                            Alasannya bukan penyederhanaan: Trakteer
                            menyambungkan pembayaran ke tagihan lewat pesan yang
                            diketik pendukung, dan nomor tagihan itu harus ada di
                            tangan pengguna tepat sebelum ia menekan tautannya.
                            Di bot keduanya ada dalam satu percakapan yang bisa
                            digulir ulang; di web, nomornya tertinggal di tab yang
                            sudah ditutup.

                            Halaman ini tinggal etalase. Tombolnya tidak dirender
                            bila TELEGRAM_BOT_USERNAME belum diisi -- tautan t.me
                            tanpa nama bot tidak menuju ke mana pun, dan aturan
                            nomor 4 proyek ini melarang tombol semacam itu.
                        --}}
                        @if ($botLink)
                            <a href="{{ $botLink }}" class="btn btn-primary"
                               target="_blank" rel="noopener">
                                Berlangganan lewat Telegram
                            </a>
                        @else
                            <span class="btn btn-ghost">Berlangganan lewat bot Telegram</span>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @auth
        @if ($subscriptions->isNotEmpty())
            <section class="section section-pad">
                <x-web.home.section-header title="Riwayat Pembelian" />

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
