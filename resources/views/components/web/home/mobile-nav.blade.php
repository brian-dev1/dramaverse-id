@php
    $items = [
        ['route' => 'web.home',     'label' => 'Beranda',  'icon' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
        ['route' => 'web.search',   'label' => 'Cari',     'icon' => null],
        ['route' => 'web.trending', 'label' => 'Trending', 'icon' => 'M3 3v18h18 M19 9l-5 5-4-4-3 3'],
        ['route' => auth()->check() ? 'web.history' : 'web.latest', 'label' => auth()->check() ? 'Riwayat' : 'Terbaru', 'icon' => 'M12 8v4l3 3'],
        ['route' => auth()->check() ? 'web.profile' : 'web.membership', 'label' => auth()->check() ? 'Profil' : 'VIP', 'icon' => null],
    ];
@endphp

<nav class="mobile-nav">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}"
           class="{{ request()->routeIs($item['route']) ? 'active' : '' }}">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                @if ($item['label'] === 'Cari')
                    <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                @elseif (in_array($item['label'], ['Profil', 'VIP'], true))
                    <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                @elseif ($item['label'] === 'Riwayat')
                    <circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/>
                @else
                    <path d="{{ $item['icon'] }}"/>
                @endif
            </svg>
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
