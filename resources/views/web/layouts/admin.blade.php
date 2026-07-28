<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — DramaVerse ID</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">

    <div class="admin-shell">

        <aside class="admin-sidebar">
            <a href="{{ route('admin.dashboard') }}" class="logo">
                DramaVerse<span class="dot"></span><span class="id">ADMIN</span>
            </a>

            <nav>
                @foreach ([
                    'admin.dashboard'         => 'Dashboard',
                    'admin.drama.index'       => 'Drama',
                    'admin.episode.index'     => 'Episode',
                    'admin.genre.index'       => 'Genre',
                    'admin.country.index'     => 'Negara',
                    'admin.banner.index'      => 'Banner',
                    'admin.user.index'        => 'Pengguna',
                    'admin.membership.index'  => 'Membership',
                    'admin.subscription.index'=> 'Langganan',
                    'admin.report'            => 'Laporan',
                    'admin.logs.index'        => 'Log',
                    'admin.settings'          => 'Pengaturan',
                ] as $route => $label)
                    <a href="{{ route($route) }}"
                       class="{{ request()->routeIs($route) ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('admin.logout') }}" class="admin-logout">
                @csrf
                <button type="submit">Keluar</button>
            </form>
        </aside>

        <div class="admin-main">

            <header class="admin-topbar">
                <h1>@yield('title', 'Admin')</h1>
                <span class="admin-user">{{ auth()->user()?->name }}</span>
            </header>

            @if (session('status'))
                <div class="flash" role="status">{{ session('status') }}</div>
            @endif

            <div class="admin-content">
                @yield('content')
            </div>

        </div>
    </div>

    @stack('scripts')
</body>
</html>
