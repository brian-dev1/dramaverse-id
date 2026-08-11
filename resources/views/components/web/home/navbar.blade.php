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
            {{--
                Tujuannya `web.search.result`, BUKAN `web.search`.

                Sebelumnya mengarah ke `web.search` — halaman pencarian yang
                belum mencari apa pun. Akibatnya mengetik "cinta" lalu menekan
                Enter membawa orang ke halaman bertuliskan "Mulai mencari",
                dengan kata kuncinya sudah terisi tapi tanpa satu pun hasil.
                Kelihatan seperti "tidak ada drama berjudul cinta", padahal
                pencariannya memang belum pernah dijalankan.
            --}}
            <form action="{{ route('web.search.result') }}" method="GET" role="search"
                  data-navsearch
                  data-endpoint="{{ route('api.v1.search') }}"
                  data-request-url="{{ route('web.request.index') }}">
                <x-web.home.icon name="search" :size="16" />
                <input type="search"
                       name="q"
                       value="{{ request('q') }}"
                       placeholder="Cari drama..."
                       autocomplete="off"
                       aria-label="Cari drama"
                       data-navsearch-input>

                {{-- Panel hasil melayang. Diisi skrip; kosong saat dirender
                     supaya tidak ada ruang menggantung sebelum diketik. --}}
                <div class="nav-results" data-navsearch-panel hidden></div>
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
                    // Arahkan ke channel Telegram (TELEGRAM_CHANNEL_URL).
                    // Bila belum diisi, jatuh kembali ke halaman membership.
                    $channelUrl = trim((string) config('telegram.channel_url'));
                    $masukUrl = $channelUrl !== '' ? $channelUrl : route('web.membership');
                @endphp
                {{-- target="_blank": Telegram menolak dimuat di dalam iframe,
                     jadi tautan luar harus dibuka di tab/jendela baru. --}}
                <a href="{{ $masukUrl }}" class="btn btn-primary btn-sm"
                   @if ($channelUrl !== '') target="_blank" rel="noopener noreferrer" @endif>
                    Gabung Channel Telegram
                </a>
            @endauth

        </div>
    </div>
</header>
