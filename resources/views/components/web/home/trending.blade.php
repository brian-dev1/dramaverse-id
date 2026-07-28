@props([
    'trendingDramas' => collect(),
])

<section class="home-section container">

    <div class="section-header">

        <div class="section-left">

            <div class="section-line"></div>

            <div>

                <h2 class="section-title">

                    🔥 Trending Minggu Ini

                </h2>

                <div class="section-subtitle">

                    Drama yang sedang ramai ditonton minggu ini.

                </div>

            </div>

        </div>

        <a
            href="{{ route('web.trending') }}"
            class="section-more">

            Lihat Semua →

        </a>

    </div>

    <div class="drama-grid">

        @forelse($trendingDramas as $drama)

            <x-web.home.drama-card
                :drama="$drama"
                :rank="$loop->iteration"
            />

        @empty

            <div class="empty-state">

                <div class="empty-icon">
                    🔥
                </div>

                <h3>
                    Belum Ada Drama Trending
                </h3>

                <p>
                    Drama trending akan muncul otomatis setelah ada aktivitas penonton.
                </p>

            </div>

        @endforelse

    </div>

</section>