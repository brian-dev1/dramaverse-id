@props([
    'related' => collect(),
])

<div class="web-related-card">

    <div class="web-related-header">

        <h3>

            Drama Serupa

        </h3>

    </div>

    @forelse($related as $item)

        <a
            href="{{ route('drama.show',$item->slug) }}"
            class="web-related-item">

            <img
                src="{{ asset($item->poster) }}"
                alt="{{ $item->title }}">

            <div>

                <h4>

                    {{ $item->title }}

                </h4>

                <span>

                    {{ $item->release_year }}

                </span>

            </div>

        </a>

    @empty

        <div class="web-related-empty">

            Belum ada drama serupa.

        </div>

    @endforelse

</div>