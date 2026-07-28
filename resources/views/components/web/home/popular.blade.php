@props([
    'popularDramas' => collect(),
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            ⭐ Drama Populer

        </div>

        <a
            href="{{ route('web.popular') }}"
            class="see-all">

            Lihat Semua →

        </a>

    </div>

    <div class="rail">

        @forelse($popularDramas as $drama)

            <x-web.drama-card
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

                    Drama populer akan muncul otomatis berdasarkan jumlah penonton.

                </p>

            </div>

        @endforelse

    </div>

</section>