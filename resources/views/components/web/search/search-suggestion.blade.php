<section class="web-search-suggestion">

    <div class="container">

        <div class="web-suggestion-box">

            <div class="web-suggestion-header">

                <h3>

                    Saran Pencarian

                </h3>

            </div>

            <div class="web-suggestion-list">

                @foreach([
                    'Hidden Love',
                    'The First Frost',
                    'Love Like The Galaxy',
                    'Only For Love',
                    'The Double',
                    'When I Fly Towards You',
                    'Love Game in Eastern Fantasy',
                    'Story of Kunning Palace'
                ] as $keyword)

                <button class="web-suggestion-item">

                    🔍 {{ $keyword }}

                </button>

                @endforeach

            </div>

        </div>

    </div>

</section>