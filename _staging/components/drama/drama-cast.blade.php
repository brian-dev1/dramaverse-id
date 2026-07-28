@props([
    'casts' => collect(),
])

<section class="web-section">

    <div class="web-section-header">

        <h2 class="web-section-title">

            Pemeran

        </h2>

    </div>

    @if($casts->isEmpty())

        <div class="web-empty-card">

            <div class="web-empty-icon">

                🎭

            </div>

            <p>

                Informasi pemeran belum tersedia.

            </p>

        </div>

    @else

        <div class="web-cast-grid">

            @foreach($casts as $cast)

                <div class="web-cast-card">

                    <div class="web-cast-image">

                        <img
                            src="{{ $cast->photo ? asset($cast->photo) : asset('images/default-avatar.png') }}"
                            alt="{{ $cast->name }}">

                    </div>

                    <div class="web-cast-content">

                        <h3>

                            {{ $cast->name }}

                        </h3>

                        @if(!empty($cast->character))

                            <span>

                                {{ $cast->character }}

                            </span>

                        @endif

                    </div>

                </div>

            @endforeach

        </div>

    @endif

</section>