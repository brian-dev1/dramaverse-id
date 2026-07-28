@props([
    'trendingDramas' => collect(),
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            🔥 Trending Minggu Ini

        </div>

        <a
            href="{{ route('web.trending') }}"
            class="see-all">

            Lihat Semua →

        </a>

    </div>

    <div class="rail">

        @forelse($trendingDramas as $drama)

            <x-web.drama-card

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

                    Data trending akan otomatis muncul setelah admin
                    menambahkan drama atau sistem mulai menghitung
                    jumlah penonton.

                </p>

            </div>

        @endforelse

    </div>

</section>