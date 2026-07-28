<section class="web-drama-review">

    <div class="container">

        <div class="web-section-header">

            <div>

                <span class="web-section-subtitle">

                    Review

                </span>

                <h2 class="web-section-title">

                    Ulasan Penonton

                </h2>

            </div>

            <button class="web-review-button">

                Tulis Review

            </button>

        </div>

        <div class="web-review-list">

            @for($i = 1; $i <= 4; $i++)

                <div class="web-review-card">

                    <div class="web-review-top">

                        <div class="web-review-avatar">

                            <img
                                src="https://placehold.co/80x80"
                                alt="User">

                        </div>

                        <div class="web-review-user">

                            <h4>

                                DramaVerse User

                            </h4>

                            <span>

                                ★★★★★

                            </span>

                        </div>

                    </div>

                    <p>

                        Hidden Love menjadi salah satu drama romance terbaik
                        yang pernah saya tonton. Chemistry antar pemeran sangat
                        natural, alur ceritanya ringan tetapi mampu membuat
                        penonton terbawa suasana dari awal hingga akhir.

                    </p>

                </div>

            @endfor

        </div>

    </div>

</section>