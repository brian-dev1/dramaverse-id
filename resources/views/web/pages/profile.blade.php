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
                        &#64;{{ $user->telegram_username }} &middot;
                    @endif
                    Bergabung {{ $user->created_at->translatedFormat('F Y') }}
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
                {{ $subscription->plan?->name }} &middot; berakhir
                {{ $subscription->expired_at?->translatedFormat('d F Y') ?? '—' }}
            @else
                Anda masih memakai paket Gratis.
            @endif
        </p>
    </section>

    <x-web.home.continue-watching :histories="$continueWatching" />

    <section class="section section-pad">
        <form method="POST" action="{{ route('web.logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">Keluar</button>
        </form>
    </section>

@endsection
