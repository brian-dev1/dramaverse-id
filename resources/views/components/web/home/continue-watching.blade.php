@props(['histories'])

@if ($histories->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header
            title="Lanjutkan Menonton"
            :href="route('web.continue-watching')" />

        <div class="rail">
            @foreach ($histories as $history)
                @php
                    $total   = max((int) ($history->episode->duration ?? 0), 1);
                    $percent = min(100, (int) round(($history->progress / $total) * 100));
                @endphp

                <x-web.home.drama-card
                    :drama="$history->drama"
                    variant="continue"
                    :episode="$history->episode->episode_number"
                    :progress="$percent" />
            @endforeach
        </div>

    </section>
@endif
