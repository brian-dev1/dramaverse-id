@props([
    'drama',
])

@if(!empty($drama->trailer_url))

<section class="web-section">

    <div class="web-section-header">

        <h2 class="web-section-title">

            Trailer

        </h2>

    </div>

    <div class="web-trailer-card">

        <iframe
            src="{{ $drama->trailer_url }}"
            allowfullscreen>

        </iframe>

    </div>

</section>

@endif