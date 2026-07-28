<section class="web-profile-watchlist">

    <div class="container">

        <div class="web-section-header">

            <div>

                <span class="web-section-subtitle">

                    Watchlist

                </span>

                <h2 class="web-section-title">

                    Daftar Tonton Saya

                </h2>

            </div>

        </div>

        <div class="web-watchlist-grid">

            @for($i=1;$i<=8;$i++)

                <div class="web-watchlist-card">

                    <img
                        src="https://placehold.co/320x460"
                        alt="Drama">

                    <div class="web-watchlist-content">

                        <h3>

                            Hidden Love

                        </h3>

                        <p>

                            Ditambahkan 3 hari lalu

                        </p>

                        <button>

                            Tonton Sekarang

                        </button>

                    </div>

                </div>

            @endfor

        </div>

    </div>

</section>