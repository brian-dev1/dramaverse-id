@props(['genres'])

@if ($genres->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header title="Jelajahi Genre" :href="route('web.genre.index')" />

        <div class="pill-row">
            @foreach ($genres as $genre)
                <a href="{{ route('web.genre.show', $genre->slug) }}" class="pill">{{ $genre->name }}</a>
            @endforeach
        </div>

    </section>
@endif
