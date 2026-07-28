<section class="web-search-popular">

    <div class="container">

        <div class="web-section-header">

            <h2>

                Trending Search

            </h2>

        </div>

        <div class="web-popular-grid">

            @foreach([
                'The Prisoner of Beauty',
                'Hidden Love',
                'The Double',
                'Lighter and Princess',
                'Love Like The Galaxy',
                'Only For Love'
            ] as $drama)

                <div class="web-popular-card">

                    🔥 {{ $drama }}

                </div>

            @endforeach

        </div>

    </div>

</section>