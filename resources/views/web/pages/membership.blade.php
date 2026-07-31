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
                        <p class="plan-price">Rp {{ number_format((float) $plan->price, 0, ',', '.') }}</p>
                        <p class="plan-duration">{{ $plan->duration }} hari</p>

                        @if ($plan->description)
                            <p class="plan-desc">{{ $plan->description }}</p>
                        @endif

                        {{--
                            Sejak Phase 10 tombolnya benar-benar membuat tagihan.
                            Sebelumnya ia hanya teks yang tidak menuju ke mana pun.

                            Tombol tidak dirender bila tidak ada metode pembayaran
                            yang siap: tombol yang menjanjikan pembayaran lalu
                            dijawab "belum ada metode" adalah dead link, dan aturan
                            nomor 4 proyek ini melarangnya.
                        --}}
                        @auth
                            @if ($providers->isNotEmpty())
                                <form method="POST" action="{{ route('web.checkout') }}" class="inline-form">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $plan->slug }}">

                                    @if ($providers->count() > 1)
                                        <select name="provider" class="control control-sm">
                                            @foreach ($providers as $p)
                                                <option value="{{ $p->slug }}">{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif

                                    <button type="submit" class="btn btn-primary">Berlangganan</button>
                                </form>
                            @else
                                <span class="btn btn-ghost">Metode pembayaran belum tersedia</span>
                            @endif
                        @else
                            <span class="btn btn-ghost">Masuk dulu untuk berlangganan</span>
                        @endauth
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
                                <td>Rp {{ number_format((float) $sub->price, 0, ',', '.') }}</td>
                                <td>{{ $sub->started_at?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $sub->expired_at?->format('d M Y') ?? '—' }}</td>
                                <td>{{ ucfirst($sub->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endauth

@endsection
