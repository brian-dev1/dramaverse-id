@props([
    'popularDramas' => collect(),
])

<section class="home-section container">

    <div class="section-header">

        <div class="section-left">

            <div class="section-line"></div>

            <div>

                <h2 class="section-title">

                    ⭐ Drama Populer

                </h2>

                <div class="section-subtitle">

                    Drama dengan jumlah penonton terbanyak.

                </div>

            </div>

        </div>

        <a
            href="{{ route('web.popular') }}"
            class="section-more">

            Lihat Semua →

        </a>

    </div>

    <div class="drama-grid">

        @forelse($popularDramas as $drama)

            <x-web.home.drama-card
                :drama="$drama"
            />

        @empty

            <div class="empty-state">

                <div class="empty-icon">
                    ⭐
                </div>

                <h3>
                    Belum Ada Drama Populer
                </h3>

                <p>
                    Drama populer akan muncul berdasarkan jumlah penonton.
                </p>

            </div>

        @endforelse

    </div>

</section>