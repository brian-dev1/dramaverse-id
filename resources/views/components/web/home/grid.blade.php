@props([
    'dramas',
    'title'   => null,
    'href'    => null,
    'count'   => null,
    'variant' => 'default',
    'paginator' => null,
])

@if ($dramas->isNotEmpty())
    <section
        class="section section-pad"
        @if ($paginator)
            data-infinite-scroll
        @endif
    >

        @if ($title)
            <x-web.home.section-header
                :title="$title"
                :count="$count"
                :href="$href"
            />
        @endif

        <div
            class="grid"
            @if ($paginator)
                data-infinite-grid
            @endif
        >
            @foreach ($dramas as $drama)
                <x-web.home.drama-card
                    :drama="$drama"
                    :variant="$variant"
                />
            @endforeach
        </div>

        @if ($paginator)

            <div
                class="dv-infinite-sentinel"
                data-infinite-sentinel
                data-next-url="{{ $paginator->nextPageUrl() ?? '' }}"
                data-state="{{ $paginator->hasMorePages() ? 'idle' : 'done' }}"
                role="status"
                aria-live="polite"
            >
                <span
                    class="dv-infinite-spinner"
                    aria-hidden="true"
                ></span>

                <span data-infinite-status>
                    @if (! $paginator->hasMorePages())
                        Semua drama sudah ditampilkan
                    @endif
                </span>
            </div>

            {{-- Fallback kalau JavaScript mati --}}
            <noscript>
                @if ($paginator->hasPages())
                    <div class="pagination-wrap dv-pager">
                        {{ $paginator->links() }}
                    </div>
                @endif
            </noscript>

        @endif

    </section>
@endif