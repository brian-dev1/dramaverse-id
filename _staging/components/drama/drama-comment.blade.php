<section class="web-drama-comment">

    <div class="container">

        <div class="web-section-header">

            <div>

                <span class="web-section-subtitle">

                    Discussion

                </span>

                <h2 class="web-section-title">

                    Komentar Pengguna

                </h2>

            </div>

        </div>

        <div class="web-comment-form">

            <textarea
                placeholder="Bagikan pendapatmu mengenai drama ini..."></textarea>

            <button>

                Kirim Komentar

            </button>

        </div>

        <div class="web-comment-list">

            @for($i=1;$i<=5;$i++)

                <div class="web-comment-card">

                    <div class="web-comment-avatar">

                        <img
                            src="https://placehold.co/70x70"
                            alt="Avatar">

                    </div>

                    <div class="web-comment-content">

                        <div class="web-comment-header">

                            <h4>

                                DramaVerse User

                            </h4>

                            <span>

                                2 Jam Lalu

                            </span>

                        </div>

                        <p>

                            Salah satu drama romance terbaik tahun ini.
                            Visualnya bagus, soundtrack-nya enak didengar,
                            dan chemistry kedua pemerannya luar biasa.

                        </p>

                    </div>

                </div>

            @endfor

        </div>

    </div>

</section>