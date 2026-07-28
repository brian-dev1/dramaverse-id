@props([
    'gallery' => collect(),
])

<section class="web-section">

    <div class="web-section-header">

        <h2 class="web-section-title">

            Galeri

        </h2>

    </div>

    @if($gallery->isEmpty())

        <div class="web-empty-card">

            <div class="web-empty-icon">

                🖼️

            </div>

            <p>

                Belum ada gambar.

            </p>

        </div>

    @else

        <div class="web-gallery-grid">

            @foreach($gallery as $image)

                <a
                    href="{{ asset($image->image) }}"
                    target="_blank"
                    class="web-gallery-item">

                    <img
                        src="{{ asset($image->image) }}"
                        alt="Gallery">

                </a>

            @endforeach

        </div>

    @endif

</section>