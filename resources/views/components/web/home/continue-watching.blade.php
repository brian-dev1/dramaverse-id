@props([
    'continueWatching' => collect(),
])

<section class="home-section container">

    <div class="section-header">

        <div class="section-left">

            <div class="section-line"></div>

            <div>

                <h2 class="section-title">

                    ▶ Continue Watching

                </h2>

                <div class="section-subtitle">

                    Lanjutkan drama yang terakhir kamu tonton.

                </div>

            </div>

        </div>

        <a
            href="{{ route('web.history') }}"
            class="section-more">

            Lihat Semua →

        </a>

    </div>

    <div class="drama-grid">

        @forelse($continueWatching as $drama)

            <x-web.home.drama-card
                :drama="$drama"
                type="continue"
                :progress="$drama->progress ?? rand(10,95)"
            />

        @empty

            <div class="empty-state">

                <div class="empty-icon">

                    ▶

                </div>

                <h3>

                    Belum Ada Riwayat Tontonan

                </h3>

                <p>

                    Drama yang sedang kamu tonton akan muncul di sini.

                </p>

            </div>

        @endforelse

    </div>

</section>