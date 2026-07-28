@extends('web.layouts.admin')

@section('title', 'Laporan')

@section('content')

    <div class="stat-row">
        <div class="stat-card">
            <span class="stat-value">Rp {{ number_format((float) $revenue, 0, ',', '.') }}</span>
            <span class="stat-label">Pendapatan Langganan Aktif</span>
        </div>
    </div>

    <div class="admin-grid">

        <section>
            <h2 class="section-title">Drama Paling Banyak Ditonton</h2>
            <table class="data-table">
                <thead><tr><th>Judul</th><th>Tontonan</th><th>Rating</th></tr></thead>
                <tbody>
                    @forelse ($topDramas as $drama)
                        <tr>
                            <td>{{ $drama->title }}</td>
                            <td>{{ number_format($drama->views) }}</td>
                            <td>{{ number_format((float) $drama->rating, 1) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section>
            <h2 class="section-title">Aktivitas 14 Hari Terakhir</h2>
            <table class="data-table">
                <thead><tr><th>Tanggal</th><th>Jumlah Tontonan</th></tr></thead>
                <tbody>
                    @forelse ($watchPerDay as $row)
                        <tr><td>{{ $row->day }}</td><td>{{ number_format($row->total) }}</td></tr>
                    @empty
                        <tr><td colspan="2">Belum ada aktivitas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    </div>

@endsection
