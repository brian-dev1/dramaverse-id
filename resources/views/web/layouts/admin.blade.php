<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') — DramaVerse ID</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">

@php
    $menu = [
        ['group' => 'Utama', 'items' => [
            ['route' => 'admin.dashboard',    'icon' => 'chart',    'label' => 'Dashboard'],
        ]],
        ['group' => 'Katalog', 'items' => [
            ['route' => 'admin.drama.index',   'icon' => 'film',   'label' => 'Drama'],
            ['route' => 'admin.episode.index', 'icon' => 'list',   'label' => 'Episode'],
            ['route' => 'admin.genre.index',   'icon' => 'tag',    'label' => 'Genre'],
            ['route' => 'admin.country.index', 'icon' => 'globe',  'label' => 'Negara'],
            ['route' => 'admin.banner.index',  'icon' => 'image',  'label' => 'Banner'],
        ]],
        ['group' => 'Anggota', 'items' => [
            ['route' => 'admin.membership.index',  'icon' => 'card',  'label' => 'Membership'],
            ['route' => 'admin.subscription.index','icon' => 'card',  'label' => 'Langganan'],
            ['route' => 'admin.user.index',        'icon' => 'users', 'label' => 'Pengguna'],
            ['route' => 'admin.telegram',          'icon' => 'send',  'label' => 'Telegram'],
        ]],
        ['group' => 'Sistem', 'items' => [
            ['route' => 'admin.analytics',   'icon' => 'chart',    'label' => 'Analytics'],
            ['route' => 'admin.report',      'icon' => 'file',     'label' => 'Laporan'],
            ['route' => 'admin.logs.index',  'icon' => 'shield',   'label' => 'Log'],
            ['route' => 'admin.settings',    'icon' => 'settings', 'label' => 'Pengaturan'],
        ]],
    ];
@endphp

<div class="admin-shell" data-shell>

    <aside class="admin-sidebar" data-sidebar>

        <div class="admin-brand">
            <a href="{{ route('admin.dashboard') }}" class="logo">
                DramaVerse<span class="dot"></span><span class="id">ADMIN</span>
            </a>
            <button type="button" class="btn-icon sidebar-toggle" data-sidebar-toggle
                    aria-label="Sembunyikan menu">
                <x-web.home.icon name="menu" :size="17" />
            </button>
        </div>

        <nav class="admin-nav" aria-label="Menu admin">
            @foreach ($menu as $section)
                <p class="admin-nav-group">{{ $section['group'] }}</p>

                @foreach ($section['items'] as $item)
                    @php
                        $base   = Str::beforeLast($item['route'], '.');
                        $active = request()->routeIs($item['route'])
                            || (Str::endsWith($item['route'], '.index') && request()->routeIs($base.'.*'));
                    @endphp
                    <a href="{{ route($item['route']) }}" class="{{ $active ? 'active' : '' }}">
                        <x-web.home.icon :name="$item['icon']" :size="17" />
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" class="admin-logout">
            @csrf
            <button type="submit">
                <x-web.home.icon name="logout" :size="16" />
                <span>Keluar</span>
            </button>
        </form>

    </aside>

    <div class="admin-main">

        <header class="admin-topbar">
            <button type="button" class="btn-icon sidebar-open" data-sidebar-toggle
                    aria-label="Tampilkan menu">
                <x-web.home.icon name="menu" :size="18" />
            </button>

            <h1>@yield('title', 'Admin')</h1>

            <div class="admin-topbar-right">
                <a href="{{ route('web.home') }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener">
                    Lihat situs
                </a>
                <span class="avatar" title="{{ auth()->user()?->name }}">
                    {{ auth()->user()?->initial }}
                </span>
            </div>
        </header>

        @if (session('status'))
            <div class="toast toast-success" role="status" data-toast>
                <x-web.home.icon name="check" :size="15" />
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="toast toast-error" role="alert" data-toast>
                <x-web.home.icon name="close" :size="15" />
                {{ $errors->count() }} isian belum benar. Periksa kembali form di bawah.
            </div>
        @endif

        <div class="admin-content">
            @yield('content')
        </div>

    </div>
</div>

{{-- Dialog konfirmasi, dikendalikan resources/js/admin.js --}}
<div class="modal" data-modal hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <h2 id="modal-title" data-modal-title>Konfirmasi</h2>
        <p data-modal-message></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Batal</button>
            <button type="button" class="btn btn-danger" data-modal-confirm>Hapus</button>
        </div>
    </div>
</div>

@stack('scripts')

{{-- Chart.js hanya dimuat bila halaman benar-benar punya grafik. --}}
@if (! empty($chartsOnPage ?? false) || request()->routeIs('admin.dashboard', 'admin.report'))
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
@endif
</body>
</html>
