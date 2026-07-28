@props([
    'genres' => collect(),
    'activeGenre' => null,
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            🎭 Jelajahi Berdasarkan Genre

        </div>

    </div>

    <div class="genre-grid">

        @forelse($genres as $genre)

            <button
                class="genre-chip {{ $activeGenre == $genre->slug ? 'active' : '' }}"
                data-slug="{{ $genre->slug }}"
                data-id="{{ $genre->id }}">

                <div class="genre-icon">

                    @if($genre->icon)

                        <img
                            src="{{ asset($genre->icon) }}"
                            alt="{{ $genre->name }}">

                    @else

                        🎬

                    @endif

                </div>

                <div class="genre-content">

                    <div class="genre-name">

                        {{ $genre->name }}

                    </div>

                    <div class="genre-count">

                        {{ number_format($genre->dramas_count) }} Drama

                    </div>

                </div>

            </button>

        @empty

            <div class="empty-state">

                Belum ada genre tersedia.

            </div>

        @endforelse

    </div>

</section>