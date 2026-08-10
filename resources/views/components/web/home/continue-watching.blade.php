@props(['histories'])

@if ($histories->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header
            title="Lanjutkan Menonton"
            :href="route('web.continue-watching')" />

        <div class="rail">
            @foreach ($histories as $index => $history)
                @php
                    // `progress` sudah disimpan sebagai persen (0-100).
                    $percent = max(0, min(100, (int) $history->progress));
                @endphp

                <x-web.home.drama-card
                    :drama="$history->drama"
                    variant="continue"
                    :episode="$history->episode->episode_number"
                    :progress="$percent"
                    {{-- Bagian teratas beranda: tiga poster pertama diunduh
                         lebih awal, sisanya tetap menunggu digulir. --}}
                    :priority="$index < 3" />
            @endforeach
        </div>

    </section>
@endif
