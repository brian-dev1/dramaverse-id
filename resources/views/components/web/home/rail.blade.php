@props([
    'dramas',
    'title',
    'href'    => null,
    'count'   => null,
    'variant' => 'default',

    // Diaktifkan hanya untuk bagian paling atas halaman: tiga poster pertama
    // diunduh lebih awal supaya layar tidak kosong dulu sebelum terisi.
    'priority' => false,
])

@if ($dramas->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header :title="$title" :count="$count" :href="$href" />

        <div class="rail-wrap">
            <button type="button" class="rail-arrow rail-arrow-prev" aria-label="Geser ke kiri" hidden>
                <x-web.home.icon name="arrow-left" :size="18" />
            </button>

            <div class="rail">
                @foreach ($dramas as $index => $drama)
                    <x-web.home.drama-card
                        :drama="$drama"
                        :variant="$variant"
                        :rank="$index + 1"
                        :priority="$priority && $index < 3" />
                @endforeach
            </div>

            <button type="button" class="rail-arrow rail-arrow-next" aria-label="Geser ke kanan" hidden>
                <x-web.home.icon name="arrow-right" :size="18" />
            </button>
        </div>

    </section>
@endif
