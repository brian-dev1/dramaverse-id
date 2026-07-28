<section class="web-about-workflow">

    <div class="container">

        <div class="web-section-header">

            <span class="web-section-subtitle">

                How It Works

            </span>

            <h2 class="web-section-title">

                Cara Menggunakan DramaVerse

            </h2>

        </div>

        <div class="web-workflow-grid">

            @foreach([
                ['🔍','Cari Drama'],
                ['📖','Lihat Detail'],
                ['🤖','Buka Telegram'],
                ['▶️','Streaming']
            ] as $step)

            <div class="web-workflow-card">

                <div class="web-workflow-icon">

                    {{ $step[0] }}

                </div>

                <h3>

                    {{ $step[1] }}

                </h3>

            </div>

            @endforeach

        </div>

    </div>

</section>