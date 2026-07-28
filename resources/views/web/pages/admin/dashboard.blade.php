@extends('web.layouts.admin')

@section('title', 'Dashboard')

@section('content')

    <div class="stat-row">
        @foreach ([
            'Drama'          => $stats['dramas'],
            'Episode'        => $stats['episodes'],
            'Pengguna'       => $stats['users'],
            'Langganan Aktif'=> $stats['active'],
            'Total Tontonan' => $stats['watched'],
        ] as $label => $value)
            <div class="stat-card">
                <span class="stat-value">{{ number_format($value) }}</span>
                <span class="stat-label">{{ $label }}</span>
            </div>
        @endforeach
    </div>

    <div class="admin-grid">

        <section>
            <h2 class="section-title">Drama Terbaru</h2>
            <table class="data-table">
                <thead><tr><th>Judul</th><th>Status</th><th>Rating</th><th>Dibuat</th></tr></thead>
                <tbody>
                    @forelse ($latestDramas as $drama)
                        <tr>
                            <td>{{ $drama->title }}</td>
                            <td>{{ $drama->status }}</td>
                            <td>{{ number_format((float) $drama->rating, 1) }}</td>
                            <td>{{ $drama->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada drama.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section>
            <h2 class="section-title">Pengguna Terbaru</h2>
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Telegram</th><th>Bergabung</th></tr></thead>
                <tbody>
                    @forelse ($latestUsers as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->telegram_username ? '@'.$user->telegram_username : '—' }}</td>
                            <td>{{ $user->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Belum ada pengguna.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    </div>

@endsection
