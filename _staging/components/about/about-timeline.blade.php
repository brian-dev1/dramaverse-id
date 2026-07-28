<section class="web-about-timeline">

    <div class="container">

        <div class="web-section-header">

            <span class="web-section-subtitle">
                Journey
            </span>

            <h2 class="web-section-title">
                Perjalanan DramaVerse
            </h2>

        </div>

        <div class="web-timeline">

            @foreach([
                ['2025','DramaVerse mulai dikembangkan.'],
                ['2026','Website resmi diluncurkan.'],
                ['2026','Integrasi Telegram Premium.'],
                ['2027','Ribuan drama tersedia.']
            ] as $item)

            <div class="web-timeline-item">

                <div class="web-timeline-year">
                    {{ $item[0] }}
                </div>

                <div class="web-timeline-content">

                    <h3>{{ $item[0] }}</h3>

                    <p>{{ $item[1] }}</p>

                </div>

            </div>

            @endforeach

        </div>

    </div>

</section>