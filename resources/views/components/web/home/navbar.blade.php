<header class="navbar">

    <div class="navbar-inner">

        {{-- Logo --}}
        <a href="{{ route('web.home') }}" class="logo">

            DramaVerse

            <span class="dot"></span>

            <span class="id">
                ID
            </span>

        </a>

        {{-- Menu --}}
        <nav class="nav-links">

            <a href="{{ route('web.home') }}"
               class="{{ request()->routeIs('web.home') ? 'active' : '' }}">
                Beranda
            </a>

            <a href="#">
                Trending
            </a>

            <a href="#">
                Terbaru
            </a>

            <a href="#">
                Genre
            </a>

            <a href="#">
                Negara
            </a>

            <a href="#">
                Membership
            </a>

        </nav>

        {{-- Right --}}
        <div class="nav-right">

            {{-- Search --}}
            <button
                class="search-pill"
                id="open-search">

                <svg
                    width="15"
                    height="15"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2">

                    <circle
                        cx="11"
                        cy="11"
                        r="7"/>

                    <path
                        d="m21 21-4.3-4.3"/>

                </svg>

                <span>

                    Cari drama...

                </span>

            </button>

            {{-- Notification --}}
            <button
                class="icon-btn"
                aria-label="Notifikasi">

                <svg
                    width="19"
                    height="19"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2">

                    <path
                        d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>

                    <path
                        d="M13.7 21a2 2 0 0 1-3.4 0"/>

                </svg>

            </button>

            {{-- Telegram User --}}
            <button
                class="avatar">

                {{ strtoupper(substr(auth()->user()->name ?? 'G',0,1)) }}

            </button>

        </div>

    </div>

</header>