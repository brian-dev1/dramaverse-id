@props([
    'reviews' => collect(),
])

<section class="web-section">

    <div class="web-section-header">

        <h2 class="web-section-title">

            Review Pengguna

        </h2>

    </div>

    @forelse($reviews as $review)

        <div class="web-review-card">

            <div class="web-review-header">

                <strong>

                    {{ $review->user->name ?? 'Anonymous' }}

                </strong>

                <span>

                    ⭐ {{ number_format($review->rating,1) }}

                </span>

            </div>

            <p>

                {{ $review->review }}

            </p>

        </div>

    @empty

        <div class="web-empty-card">

            Belum ada review.

        </div>

    @endforelse

</section>