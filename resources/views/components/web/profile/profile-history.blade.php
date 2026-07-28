<section class="web-profile-history">

    <div class="container">

        <div class="web-section-header">

            <div>

                <span class="web-section-subtitle">

                    History

                </span>

                <h2 class="web-section-title">

                    Riwayat Menonton

                </h2>

            </div>

        </div>

        <div class="web-history-list">

            @for($i = 1; $i <= 5; $i++)

                <div class="web-history-card">

                    <img
                        src="https://placehold.co/120x170"
                        alt="Drama">

                    <div class="web-history-content">

                        <h3>

                            Hidden Love

                        </h3>

                        <p>

                            Episode terakhir ditonton: Episode 18

                        </p>

                        <span>

                            2 Jam yang lalu

                        </span>

                    </div>

                    <button>

                        Lanjutkan

                    </button>

                </div>

            @endfor

        </div>

    </div>

</section>