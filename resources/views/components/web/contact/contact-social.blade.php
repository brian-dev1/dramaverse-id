<section class="web-contact-social">

    <div class="container">

        <div class="web-section-header">

            <span class="web-section-subtitle">

                Community

            </span>

            <h2 class="web-section-title">

                Ikuti DramaVerse

            </h2>

            <p>

                Tetap terhubung bersama DramaVerse melalui berbagai platform resmi.

            </p>

        </div>

        <div class="web-contact-social-grid">

            @foreach([
                ['Telegram','@DramaVerseID'],
                ['Instagram','@dramaverse.id'],
                ['TikTok','@dramaverse.id'],
                ['Facebook','DramaVerse Indonesia']
            ] as $social)

            <div class="web-contact-social-card">

                <div class="web-contact-social-icon">

                    🌐

                </div>

                <h3>

                    {{ $social[0] }}

                </h3>

                <span>

                    {{ $social[1] }}

                </span>

                <button>

                    Kunjungi

                </button>

            </div>

            @endforeach

        </div>

    </div>

</section>