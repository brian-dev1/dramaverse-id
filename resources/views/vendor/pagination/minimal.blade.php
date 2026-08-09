@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Navigasi halaman">

        @if ($paginator->onFirstPage())
            <span class="pager-btn is-disabled" aria-disabled="true">&lsaquo;</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya">&lsaquo;</a>
        @endif

        @foreach ($elements as $element)

            @if (is_string($element))
                <span class="pager-gap">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-btn is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pager-btn" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif

        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya">&rsaquo;</a>
        @else
            <span class="pager-btn is-disabled" aria-disabled="true">&rsaquo;</span>
        @endif

    </nav>
@endif
