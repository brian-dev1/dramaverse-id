@extends('web.layouts.app')

@section('title', $title)

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">{{ $title }}</h1>
        <p class="page-subtitle">Lanjutkan dari tempat Anda berhenti.</p>
    </section>

    @if ($histories->isEmpty())
        <x-web.home.empty-state
            title="Belum ada riwayat"
            message="Mulai menonton, dan judulnya akan muncul di sini."
            :href="route('web.trending')" action="Lihat Trending" />
    @else
        <section class="section section-pad">
            <div class="grid">
                @foreach ($histories as $history)
                    @php
                        // `progress` sudah disimpan sebagai persen (0-100).
                        $percent = max(0, min(100, (int) $history->progress));
                    @endphp

                    <x-web.home.drama-card
                        :drama="$history->drama"
                        variant="continue"
                        :episode="$history->episode->episode_number"
                        :progress="$percent" />
                @endforeach
            </div>
        </section>

        <div class="section-pad pagination-wrap">{{ $histories->links() }}</div>
    @endif

@endsection
