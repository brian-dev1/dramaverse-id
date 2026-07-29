@props([
    'id',
    'title',
    'labels',
    'values',
    'type'  => 'line',
    'color' => '#D9AF6E',
    'money' => false,
])

@php
    $isEmpty = collect($values)->sum() == 0;
@endphp

<section class="chart-card">
    <h2>{{ $title }}</h2>

    @if ($isEmpty)
        <p class="chart-empty">Belum ada data untuk periode ini.</p>
    @else
        <div class="chart-canvas">
            <canvas id="{{ $id }}"
                    data-chart
                    data-type="{{ $type }}"
                    data-color="{{ $color }}"
                    data-money="{{ $money ? '1' : '0' }}"
                    data-labels='@json($labels)'
                    data-values='@json($values)'></canvas>
        </div>
    @endif
</section>
