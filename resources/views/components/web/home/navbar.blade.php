<header class="navbar">
    <div class="navbar-inner">

        {{-- Logo --}}
        <a href="{{ route('web.home') }}" class="logo">
            DramaVerse<span class="dot"></span><span class="id">ID</span>
        </a>

        {{-- Menu utama --}}
        <nav class="nav-links">
            @foreach ([
                'web.home'        => 'Beranda',
                'web.trending'    => 'Trending',
                'web.latest'      => 'Terbaru',
                'web.genre.index' => 'Genre',
                'web.country.index' => 'Negara',
                'web.membership'  => 'Membership',
            ] as $route => $label)
                <a href="{{ route($route) }}"
                   class="{{ request()->routeIs($route) ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="nav-right">

            {{-- Pencarian --}}
            <a href="{{ route('web.search') }}" class="search-pill">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                </svg>
                <span>Cari drama...</span>
            </a>

            @auth
                {{-- Notifikasi --}}
                <a href="{{ route('web.notifications') }}" class="icon-btn" aria-label="Notifikasi">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
                    </svg>
                </a>

                {{-- Profil --}}
                <a href="{{ route('web.profile') }}" class="avatar"
                   title="{{ auth()->user()->display_name }}">
                    {{ auth()->user()->initial }}
                </a>
            @else
                <a href="{{ route('web.membership') }}" class="btn btn-primary btn-sm">
                    Masuk via Telegram
                </a>
            @endauth

        </div>
    </div>
</header>
