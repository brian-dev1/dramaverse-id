<section class="web-search-recent">

    <div class="container">

        <div class="web-section-header">

            <h2>

                Pencarian Terakhir

            </h2>

        </div>

        <div class="web-recent-search-list">

            @foreach([
                'Hidden Love',
                'The First Frost',
                'When I Fly Towards You',
                'Love Game in Eastern Fantasy',
                'Only For Love'
            ] as $keyword)

                <button>

                    {{ $keyword }}

                </button>

            @endforeach

        </div>

    </div>

</section>