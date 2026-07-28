<footer class="footer">

    <div class="container">

        <div class="footer-grid">

            {{-- Brand --}}
            <div class="footer-brand">

                <a href="{{ route('web.home') }}" class="footer-logo">

                    <span>🎬</span>

                    <strong>DramaVerse</strong>

                </a>

                <p>

                    Platform streaming drama Asia modern.
                    Nikmati ribuan drama Korea, China,
                    Jepang, Thailand dan lainnya dalam satu tempat.

                </p>

                <div class="footer-social">

                    <a href="#">
                        <i class="fab fa-telegram"></i>
                    </a>

                    <a href="#">
                        <i class="fab fa-discord"></i>
                    </a>

                    <a href="#">
                        <i class="fab fa-facebook"></i>
                    </a>

                    <a href="#">
                        <i class="fab fa-instagram"></i>
                    </a>

                </div>

            </div>

            {{-- Explore --}}
            <div>

                <h4>

                    Explore

                </h4>

                <ul>

                    <li>

                        <a href="{{ route('web.home') }}">

                            Home

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.trending') }}">

                            Trending

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.latest') }}">

                            Latest Release

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.popular') }}">

                            Popular

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

                        <a href="{{ route('web.account') }}">

                            My Account

                        </a>

                    </li>

                    <li>

                        <a href="{{ route('web.history') }}">

                            Watch History

                        </a>

                    </li>

                </ul>

            </div>

            {{-- Support --}}
            <div>

                <h4>

                    Support

                </h4>

                <ul>

                    <li>

                        <a href="#">

                            Help Center

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Privacy Policy

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Terms of Service

                        </a>

                    </li>

                    <li>

                        <a href="#">

                            Contact

                        </a>

                    </li>

                </ul>

            </div>

        </div>

        <div class="footer-bottom">

            <span>

                © {{ now()->year }} DramaVerse.
                All Rights Reserved.

            </span>

            <span>

                Made with ❤️ in Indonesia

            </span>

        </div>

    </div>

</footer>