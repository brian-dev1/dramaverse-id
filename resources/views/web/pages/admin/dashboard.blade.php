@extends('web.layouts.admin')

@section('title', 'Dashboard')

@section('content')

    {{-- Ringkasan --}}
    <div class="stat-row">
        <x-admin.stat-card label="Drama"    :value="$summary['dramas']"   icon="film"  :href="route('admin.drama.index')" />
        <x-admin.stat-card label="Part"  :value="$summary['episodes']" icon="list"  :href="route('admin.episode.index')" />
        <x-admin.stat-card label="Pengguna" :value="$summary['users']"    icon="users" :href="route('admin.user.index')" />
        <x-admin.stat-card label="Pengguna Telegram" :value="$summary['telegramUsers']" icon="send" />
        <x-admin.stat-card label="Aktif 30 hari"     :value="$summary['activeUsers']"   icon="user" />
    </div>

    <div class="stat-row">
        <x-admin.stat-card label="Anggota VIP"     :value="$summary['vipMembers']"     icon="card" :href="route('admin.subscription.index')" />
        <x-admin.stat-card label="Anggota Premium" :value="$summary['premiumMembers']" icon="card" :href="route('admin.subscription.index')" />
        <x-admin.stat-card label="Total tontonan"  :value="$summary['totalViews']"     icon="chart" />
        <x-admin.stat-card label="Tontonan hari ini" :value="$summary['watchToday']"   icon="clock" />
        @can('finance.view')
            <x-admin.stat-card label="Pendapatan aktif" :value="$summary['revenue']"   icon="card" money />
        @endcan
    </div>

    {{-- Grafik --}}
    <div class="chart-grid">
        <x-admin.chart id="chart-watch"
                       title="Tontonan 14 hari terakhir"
                       :labels="$watchPerDay['labels']"
                       :values="$watchPerDay['values']" />

        <x-admin.chart id="chart-users"
                       title="Pengguna baru 30 hari terakhir"
                       type="bar"
                       color="#C1425B"
                       :labels="$userGrowth['labels']"
                       :values="$userGrowth['values']" />
    </div>

    {{-- Peringkat --}}
    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head">
                <h2>Drama terpopuler</h2>
                <a href="{{ route('admin.drama.index') }}" class="see-all">Kelola</a>
            </div>

            <table class="data-table">
                <thead><tr><th>Judul</th><th>Tontonan</th></tr></thead>
                <tbody>
                    @forelse ($topDramas as $drama)
                        <tr>
                            <td>{{ $drama->title }}</td>
                            <td>{{ number_format($drama->views) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada drama.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Genre terbanyak</h2>
                <a href="{{ route('admin.genre.index') }}" class="see-all">Kelola</a>
            </div>

            <table class="data-table">
                <thead><tr><th>Genre</th><th>Jumlah drama</th></tr></thead>
                <tbody>
                    @forelse ($topGenres as $genre)
                        <tr>
                            <td>{{ $genre->name }}</td>
                            <td>{{ $genre->dramas_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada genre.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Negara terbanyak</h2>
                <a href="{{ route('admin.country.index') }}" class="see-all">Kelola</a>
            </div>

            <table class="data-table">
                <thead><tr><th>Negara</th><th>Jumlah drama</th></tr></thead>
                <tbody>
                    @forelse ($topCountries as $country)
                        <tr>
                            <td>
                                <x-web.home.country-badge :country="$country" />
                                {{ $country->name }}
                            </td>
                            <td>{{ $country->dramas_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada negara.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Aktivitas terkini</h2>
                <a href="{{ route('admin.logs.index') }}" class="see-all">Semua log</a>
            </div>

            <table class="data-table">
                <thead><tr><th>Keterangan</th><th>Oleh</th><th>Waktu</th></tr></thead>
                <tbody>
                    @forelse ($recentLogs as $log)
                        <tr>
                            <td>{{ $log->description }}</td>
                            <td>{{ $log->user?->name ?? '—' }}</td>
                            <td>{{ $log->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum ada aktivitas.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    </div>

@endsection
