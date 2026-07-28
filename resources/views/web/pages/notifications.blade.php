@extends('web.layouts.app')

@section('title', 'Notifikasi')

@section('content')

    <section class="page-head section-pad">
        <div class="head-row">
            <div>
                <h1 class="page-title">Notifikasi</h1>
                <p class="page-subtitle">Episode baru, membership, dan pengumuman.</p>
            </div>

            @if ($notifications->isNotEmpty())
                <form method="POST" action="{{ route('web.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost">Tandai Sudah Dibaca</button>
                </form>
            @endif
        </div>
    </section>

    @if ($notifications->isEmpty())
        <x-web.home.empty-state
            title="Tidak ada notifikasi"
            message="Kami akan memberi tahu saat ada episode baru."
            :href="route('web.home')" action="Kembali ke Beranda" />
    @else
        <section class="section section-pad">
            <ul class="notif-list">
                @foreach ($notifications as $notif)
                    <li class="notif-item {{ $notif->read_at ? '' : 'unread' }}">
                        <strong>{{ $notif->title }}</strong>
                        <p>{{ $notif->message }}</p>
                        <time>{{ $notif->created_at->diffForHumans() }}</time>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="section-pad pagination-wrap">{{ $notifications->links() }}</div>
    @endif

@endsection
