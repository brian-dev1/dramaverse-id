@props([
    'latestDramas' => collect(),
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            ✨ Rilis Terbaru

        </div>

        <a
            href="{{ route('web.latest') }}"
            class="see-all">

            Lihat Semua →

        </a>

    </div>

    <div class="rail">

        @forelse($latestDramas as $drama)

            <x-web.drama-card
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

                    Drama terbaru akan otomatis muncul setelah admin
                    menambahkan atau menerbitkan drama baru.

                </p>

            </div>

        @endforelse

    </div>

</section>