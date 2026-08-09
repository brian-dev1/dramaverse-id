<header class="navbar">
    <div class="navbar-inner">

        {{-- Logo --}}
        <x-web.home.brand :href="route('web.home')" text="DramaVerse" badge="ID" />

        {{-- Menu utama --}}
        <nav class="nav-links">
            @foreach ([
                'web.home'        => 'Beranda',
                'web.trending'    => 'Trending',
                'web.latest'      => 'Terbaru',
                'web.genre.index' => 'Genre',
            ] as $route => $label)
                <a href="{{ route($route) }}"
                   class="{{ request()->routeIs($route) ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Pencarian di tengah --}}
        <div class="nav-search">
            <form action="{{ route('web.search') }}" method="GET" role="search">
                <x-web.home.icon name="search" :size="16" />
                <input type="search"
                       name="q"
                       value="{{ request('q') }}"
                       placeholder="Cari drama..."
                       autocomplete="off"
                       aria-label="Cari drama">
            </form>
        </div>

        <div class="nav-right">

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
                @php
                    // Arahkan ke bot Telegram. Bila TELEGRAM_BOT_USERNAME belum
                    // diisi, jatuh kembali ke halaman membership seperti semula.
                    $botUsername = trim((string) config('telegram.bot_username'), " \t@");
                    $masukUrl = $botUsername !== ''
                        ? 'https://t.me/'.$botUsername
                        : route('web.membership');
                @endphp
                <a href="{{ $masukUrl }}" class="btn btn-primary btn-sm" rel="noopener">
                    Masuk via Telegram
                </a>
            @endauth

        </div>
    </div>
</header>
