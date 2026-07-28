@props([
    'watchHistories' => collect(),
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            Lanjutkan Menonton

        </div>

        <a
            href="{{ route('web.history') }}"
            class="see-all">

            Lihat Semua →

        </a>

    </div>

    <div class="rail">

        @forelse($watchHistories as $history)

            <x-web.drama-card

                :drama="$history->drama"

                type="continue"

                :progress="$history->progress"

            />

        @empty

            <div
                style="
                    width:100%;
                    padding:50px;
                    text-align:center;
                    color:var(--text-secondary);
                ">

                Belum ada riwayat menonton.

            </div>

        @endforelse

    </div>

</section>