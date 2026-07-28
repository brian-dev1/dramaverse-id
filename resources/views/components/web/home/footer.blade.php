<footer class="footer wrap">

    <div class="footer-grid">

        <div class="footer-brand">
            <a href="{{ route('web.home') }}" class="logo">
                DramaVerse<span class="dot"></span><span class="id">ID</span>
            </a>
            <p>
                Platform streaming drama Asia — Korea, Tiongkok, Thailand, Jepang,
                dan lainnya, dengan subtitle Bahasa Indonesia.
            </p>
        </div>

        <div class="footer-col">
            <h4>Jelajahi</h4>
            <a href="{{ route('web.trending') }}">Trending</a>
            <a href="{{ route('web.latest') }}">Rilis Terbaru</a>
            <a href="{{ route('web.top-rated') }}">Rating Tertinggi</a>
            <a href="{{ route('web.genre.index') }}">Genre</a>
            <a href="{{ route('web.country.index') }}">Negara</a>
        </div>

        <div class="footer-col">
            <h4>Akun</h4>
            @auth
                <a href="{{ route('web.profile') }}">Profil Saya</a>
                <a href="{{ route('web.history') }}">Riwayat Tonton</a>
                <a href="{{ route('web.my-list') }}">Daftar Saya</a>
                <a href="{{ route('web.favorites') }}">Favorit</a>
                <a href="{{ route('web.settings') }}">Pengaturan</a>
            @else
                <a href="{{ route('web.membership') }}">Membership</a>
                <a href="{{ route('web.vip') }}">Koleksi VIP</a>
            @endauth
        </div>

        <div class="footer-col">
            <h4>Bantuan</h4>
            <a href="{{ route('web.help') }}">Pusat Bantuan</a>
            <a href="{{ route('web.about') }}">Tentang Kami</a>
        </div>

        <div class="footer-col">
            <h4>Legal</h4>
            <a href="{{ route('web.terms') }}">Ketentuan Layanan</a>
            <a href="{{ route('web.privacy') }}">Kebijakan Privasi</a>
        </div>

    </div>

    <div class="footer-bottom">
        <span>&copy; {{ now()->year }} DramaVerse ID. Seluruh hak cipta dilindungi.</span>
        <span>Dibuat untuk pencinta drama Asia</span>
    </div>

</footer>
