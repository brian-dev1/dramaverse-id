@php
    // "Cari" sengaja tidak ada di sini: kolom pencarian sudah tersedia
    // di bilah atas, jadi tempatnya dipakai menu lain yang sudah ada.
    $items = auth()->check()
        ? [
            ['route' => 'web.home',        'icon' => 'home',  'label' => 'Beranda'],
            ['route' => 'web.trending',    'icon' => 'trend', 'label' => 'Trending'],
            ['route' => 'web.latest',      'icon' => 'clock', 'label' => 'Terbaru'],
            ['route' => 'web.genre.index', 'icon' => 'tag',   'label' => 'Genre'],
            ['route' => 'web.profile',     'icon' => 'user',  'label' => 'Profil'],
        ]
        : [
            ['route' => 'web.home',        'icon' => 'home',  'label' => 'Beranda'],
            ['route' => 'web.trending',    'icon' => 'trend', 'label' => 'Trending'],
            ['route' => 'web.latest',      'icon' => 'clock', 'label' => 'Terbaru'],
            ['route' => 'web.popular',     'icon' => 'star',  'label' => 'Populer'],
            ['route' => 'web.genre.index', 'icon' => 'tag',   'label' => 'Genre'],
        ];
@endphp

<nav class="mobile-nav" aria-label="Navigasi utama">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}"
           class="{{ request()->routeIs($item['route']) ? 'active' : '' }}">
            <x-web.home.icon :name="$item['icon']" :size="19" />
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
