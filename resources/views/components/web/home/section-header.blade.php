@props([
    'title',
    'count' => null,
    'href'  => null,
])

<div class="section-head">

    <h2 class="section-title">
        {{ $title }}
        @if ($count)
            <span class="count">{{ $count }}</span>
        @endif
    </h2>

    @if ($href)
        <a href="{{ $href }}" class="see-all">Lihat Semua <x-web.home.icon name="arrow-right" :size="13" /></a>
    @endif

</div>
