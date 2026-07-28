<footer class="web-footer">

    <div class="web-container">

        <div class="web-footer-grid">

            {{-- Brand --}}

            <div class="web-footer-brand">

                <a
                    href="{{ route('web.home') }}"
                    class="web-footer-logo">

                    DramaVerse

                    <span>ID</span>

                </a>

                <p>

                    DramaVerse ID adalah platform private streaming drama
                    yang hanya dapat diakses melalui Telegram.

                </p>

                <div class="web-footer-social">

                    <a href="#">
                        Telegram
                    </a>

                    <a href="#">
                        Discord
                    </a>

                    <a href="#">
                        Facebook
                    </a>

                </div>

            </div>

            {{-- Navigation --}}

            <div>

                <h4>

                    Jelajahi

                </h4>

                <ul>

                    <li>

                        <a href="{{ route('web.home') }}">

                            Beranda

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.search') }}">

                            Cari Drama

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.latest') }}">

                            Terbaru

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.popular') }}">

                            Populer

                        </a>

                    </li>

                </ul>

            </div>

            {{-- Membership --}}

            <div>

                <h4>

                    Membership

                </h4>

                <ul>

                    <li>

                        <a href="{{ route('web.membership') }}">

                            Premium

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.membership') }}">

                            Paket Bulanan

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.membership') }}">

                            Paket Tahunan

                        </a>

                    </li>

                </ul>

            </div>

            {{-- Support --}}

            <div>

                <h4>

                    Bantuan

                </h4>

                <ul>

                    <li>

                        <a href="#">

                            FAQ

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Hubungi Kami

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Kebijakan Privasi

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Terms of Service

                        </a>

                    </li>

                </ul>

            </div>

        </div>

        <div class="web-footer-bottom">

            <div>

                © {{ date('Y') }}

                DramaVerse ID.

                All Rights Reserved.

            </div>

            <div>

                Build with Laravel 12

            </div>

        </div>

    </div>

</footer>