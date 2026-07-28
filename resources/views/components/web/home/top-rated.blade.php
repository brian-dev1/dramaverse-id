@props([
    'topRatedDramas' => collect(),
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            🏆 Top Rated

        </div>

        <a
            href="{{ route('web.top-rated') }}"
            class="see-all">

            Lihat Semua →

        </a>

    </div>

    <div class="rail">

        @forelse($topRatedDramas as $drama)

            <x-web.drama-card
                :drama="$drama"
                type="toprated"
                :rank="$loop->iteration"
            />

        @empty

            <div class="empty-state">

                <div class="empty-icon">

                    🏆

                </div>

                <h3>

                    Belum Ada Data Rating

                </h3>

                <p>

                    Drama dengan rating terbaik akan muncul di sini setelah mendapatkan penilaian dari pengguna.

                </p>

            </div>

        @endforelse

    </div>

</section>