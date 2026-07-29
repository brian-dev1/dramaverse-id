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
                <x-web.home.icon name="search" :size="15" />
                <span>Cari drama...</span>
            </a>

            @auth
                {{-- Notifikasi --}}
                <a href="{{ route('web.notifications') }}" class="icon-btn" aria-label="Notifikasi">
                    <x-web.home.icon name="bell" :size="19" />
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
