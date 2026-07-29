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
                    <span class="meta-item">Bergabung {{ $user->created_at->translatedFormat('F Y') }}</span>
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
        <x-web.home.section-header title="Membership" :href="route('web.membership')" />
        <p class="page-subtitle">
            @if ($subscription)
                <span class="meta-item">{{ $subscription->plan?->name }}</span>
                <span class="meta-item">Berakhir {{ $subscription->expired_at?->translatedFormat('d F Y') ?? 'tidak diketahui' }}</span>
            @else
                Anda masih memakai paket Gratis.
            @endif
        </p>
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
