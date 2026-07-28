@props([
    'dramas',
    'title'   => null,
    'href'    => null,
    'count'   => null,
    'variant' => 'default',
])

@if ($dramas->isNotEmpty())
    <section class="section section-pad">

        @if ($title)
            <x-web.home.section-header :title="$title" :count="$count" :href="$href" />
        @endif

        <div class="grid">
            @foreach ($dramas as $drama)
                <x-web.home.drama-card :drama="$drama" :variant="$variant" />
            @endforeach
        </div>

    </section>
@endif
