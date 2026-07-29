@extends('web.layouts.admin')

@section('title', 'Analytics')

@php $chartsOnPage = true; @endphp

@section('content')

    <div class="chart-grid">
        <x-admin.chart id="an-watch-day"   title="Tontonan 30 hari terakhir"
                       :labels="$watchPerDay['labels']"   :values="$watchPerDay['values']" />

        <x-admin.chart id="an-watch-month" title="Tontonan 12 bulan terakhir" type="bar" color="#5B4B8A"
                       :labels="$watchPerMonth['labels']" :values="$watchPerMonth['values']" />

        <x-admin.chart id="an-users"       title="Pengguna baru 30 hari terakhir" type="bar" color="#C1425B"
                       :labels="$userGrowth['labels']"    :values="$userGrowth['values']" />

        <x-admin.chart id="an-revenue"     title="Pendapatan 12 bulan terakhir" color="#EAC98C" money
                       :labels="$revenue['labels']"       :values="$revenue['values']" />
    </div>

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head"><h2>Paling banyak ditonton</h2></div>
            <table class="data-table">
                <thead><tr><th>Judul</th><th>Tontonan</th><th>Riwayat</th></tr></thead>
                <tbody>
                    @forelse ($topDramas as $drama)
                        <tr>
                            <td>{{ $drama->title }}</td>
                            <td>{{ number_format($drama->views) }}</td>
                            <td>{{ number_format($drama->watch_histories_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum ada data.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Drama trending</h2></div>
            <table class="data-table">
                <thead><tr><th>Judul</th><th>Skor</th><th>Tontonan</th></tr></thead>
                <tbody>
                    @forelse ($trending as $drama)
                        <tr>
                            <td>{{ $drama->title }}</td>
                            <td>{{ number_format($drama->trending_score) }}</td>
                            <td>{{ number_format($drama->views) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum ada drama bertanda trending.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Genre teratas</h2></div>
            <table class="data-table">
                <thead><tr><th>Genre</th><th>Jumlah drama</th></tr></thead>
                <tbody>
                    @forelse ($topGenres as $genre)
                        <tr><td>{{ $genre->name }}</td><td>{{ $genre->dramas_count }}</td></tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada genre.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Negara teratas</h2></div>
            <table class="data-table">
                <thead><tr><th>Negara</th><th>Jumlah drama</th></tr></thead>
                <tbody>
                    @forelse ($topCountries as $country)
                        <tr>
                            <td><x-web.home.country-badge :country="$country" /> {{ $country->name }}</td>
                            <td>{{ $country->dramas_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada negara.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Pengguna paling aktif</h2></div>
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Telegram</th><th>Tontonan</th><th>Terakhir aktif</th></tr></thead>
                <tbody>
                    @forelse ($activeUsers as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->telegram_username ? '@'.$user->telegram_username : '—' }}</td>
                            <td>{{ number_format($user->watch_histories_count) }}</td>
                            <td>{{ $user->last_seen_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><span class="cell-empty">Belum ada pengguna.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    </div>

@endsection
