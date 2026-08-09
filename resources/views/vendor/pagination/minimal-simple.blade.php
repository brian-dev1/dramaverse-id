@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Navigasi halaman">

        @if ($paginator->onFirstPage())
            <span class="pager-btn is-disabled" aria-disabled="true">&lsaquo; Sebelumnya</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Sebelumnya</a>
        @endif

        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya &rsaquo;</a>
        @else
            <span class="pager-btn is-disabled" aria-disabled="true">Berikutnya &rsaquo;</span>
        @endif

    </nav>
@endif
