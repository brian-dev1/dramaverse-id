@props([
    'dramas',
    'title',
    'href'    => null,
    'count'   => null,
    'variant' => 'default',
])

@if ($dramas->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header :title="$title" :count="$count" :href="$href" />

        <div class="rail">
            @foreach ($dramas as $index => $drama)
                <x-web.home.drama-card
                    :drama="$drama"
                    :variant="$variant"
                    :rank="$index + 1" />
            @endforeach
        </div>

    </section>
@endif
