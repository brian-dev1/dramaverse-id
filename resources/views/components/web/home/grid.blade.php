@props([
    'dramas',
    'title'   => null,
    'href'    => null,
    'count'   => null,
    'variant' => 'default',

    // Paginator opsional. Diberikan, tautan halamannya dirender DI DALAM
    // section yang sama supaya jaraknya ikut aturan section — bukan
    // menggantung sebagai blok lepas di bawahnya.
    'paginator' => null,
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

        @if ($paginator && $paginator->hasPages())
            <div class="pagination-wrap dv-pager">{{ $paginator->links() }}</div>
        @endif

    </section>
@endif
