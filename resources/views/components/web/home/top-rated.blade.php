@props([
    'topRatedDramas' => collect(),
])

<section class="home-section container">

    <div class="section-header">

        <div class="section-left">

            <div class="section-line"></div>

            <div>

                <h2 class="section-title">

                    ⭐ Top Rated

                </h2>

                <div class="section-subtitle">

                    Drama dengan rating tertinggi pilihan penonton.

                </div>

            </div>

        </div>

        <a
            href="{{ route('web.top-rated') }}"
            class="section-more">

            Lihat Semua →

        </a>

    </div>

    <div class="top-rated-grid">

        @forelse($topRatedDramas as $drama)

            <div class="top-rank">

                <div class="rank-number">

                    {{ str_pad($loop->iteration,2,'0',STR_PAD_LEFT) }}

                </div>

                <div class="rank-card">

                    <x-web.home.drama-card
                        :drama="$drama"
                    />

                    <div class="rating-chip">

                        ⭐ {{ number_format($drama->rating ?? rand(90,99)/10,1) }}

                    </div>

                </div>

            </div>

        @empty

            <div class="empty-state">

                <div class="empty-icon">

                    ⭐

                </div>

                <h3>

                    Belum Ada Data Rating

                </h3>

                <p>

                    Drama dengan rating tertinggi akan tampil di sini.

                </p>

            </div>

        @endforelse

    </div>

</section>