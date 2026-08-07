@extends('web.layouts.app')

@section('title', 'Profil')

@section('content')

    <section class="page-head section-pad">
        <div class="profile-head">
            <div class="avatar avatar-lg">{{ $user->initial }}</div>
            <div>
                <h1 class="page-title">{{ $user->display_name }}</h1>
                <p class="page-subtitle">
                    @if ($user->telegram_username)
                        <span class="meta-item">&#64;{{ $user->telegram_username }}</span>
                    @endif
                    <span class="meta-item">Bergabung {{ \App\Support\Waktu::bulan($user->created_at) }}</span>
                </p>
            </div>
        </div>
    </section>

    <section class="section section-pad">
        <div class="stat-row">
            <a href="{{ route('web.history') }}" class="stat-card">
                <span class="stat-value">{{ $stats['history'] }}</span>
                <span class="stat-label">Riwayat</span>
            </a>
            <a href="{{ route('web.favorites') }}" class="stat-card">
                <span class="stat-value">{{ $stats['favorites'] }}</span>
                <span class="stat-label">Favorit</span>
            </a>
            <a href="{{ route('web.my-list') }}" class="stat-card">
                <span class="stat-value">{{ $stats['myList'] }}</span>
                <span class="stat-label">Daftar Saya</span>
            </a>
        </div>
    </section>

    <section class="section section-pad">
        <x-web.home.section-header title="Status Akun" />

        <div class="account-panel">

            <div class="account-item">
                <span class="k">Paket</span>
                <span class="v {{ $subscription ? 'on' : 'off' }}">
                    <span class="status-dot"></span>{{ $subscription?->plan?->name ?? 'Gratis' }}
                </span>
            </div>

            <div class="account-item">
                <span class="k">Berlaku Sampai</span>
                <span class="v">
                    {{ $subscription?->expired_at ? \App\Support\Waktu::ringkas($subscription->expired_at) : 'Tanpa Batas' }}
                </span>
            </div>

            <div class="account-item">
                <span class="k">Akun Telegram</span>
                <span class="v">
                    {{ $user->telegram_username ? '@'.$user->telegram_username : 'Tersambung' }}
                </span>
            </div>

            <div class="account-item">
                <span class="k">Bergabung</span>
                <span class="v">{{ \App\Support\Waktu::tanggal($user->created_at) }}</span>
            </div>

        </div>
    </section>

    <section class="section section-pad">
        <x-web.home.section-header title="Pintasan" />
        <div class="pill-row">
            <a href="{{ route('web.history') }}" class="pill">Riwayat Tontonan</a>
            <a href="{{ route('web.favorites') }}" class="pill">Favorit</a>
            <a href="{{ route('web.my-list') }}" class="pill">Daftar Saya</a>
            <a href="{{ route('web.notifications') }}" class="pill">Notifikasi</a>
            <a href="{{ route('web.settings') }}" class="pill">Pengaturan</a>
        </div>
    </section>

    @if ($continueWatching->isEmpty() && $stats['history'] === 0)
        <x-web.home.empty-state
            title="Belum ada aktivitas"
            message="Riwayat tontonan Anda akan muncul di sini setelah mulai menonton."
            :href="route('web.trending')"
            action="Jelajahi Katalog" />
    @else
        <x-web.home.continue-watching :histories="$continueWatching" />
    @endif

    <section class="section section-pad">
        <form method="POST" action="{{ route('web.logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">Keluar</button>
        </form>
    </section>

@endsection
