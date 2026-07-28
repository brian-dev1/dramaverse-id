@props([
    'latestDramas' => collect(),
])

<section class="home-section container">

    <div class="section-header">

        <div class="section-left">

            <div class="section-line"></div>

            <div>

                <h2 class="section-title">

                    ✨ Rilis Terbaru

                </h2>

                <div class="section-subtitle">

                    Episode dan drama terbaru yang baru ditambahkan.

                </div>

            </div>

        </div>

        <a
            href="{{ route('web.latest') }}"
            class="section-more">

            Lihat Semua →

        </a>

    </div>

    <div class="drama-grid">

        @forelse($latestDramas as $drama)

            <x-web.home.drama-card
                :drama="$drama"
                type="latest"
            />

        @empty

            <div class="empty-state">

                <div class="empty-icon">
                    🎬
                </div>

                <h3>
                    Belum Ada Drama Baru
                </h3>

                <p>
                    Drama terbaru akan muncul setelah admin menambahkan data baru.
                </p>

            </div>

        @endforelse

    </div>

</section>