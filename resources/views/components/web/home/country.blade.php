@props(['countries'])

@if ($countries->isNotEmpty())
    <section class="section section-pad">

        <x-web.home.section-header title="Jelajahi Negara" :href="route('web.country.index')" />

        <div class="pill-row">
            @foreach ($countries as $country)
                <a href="{{ route('web.country.show', $country->slug) }}" class="pill">
                    {{ $country->flag_emoji }} {{ $country->name }}
                </a>
            @endforeach
        </div>

    </section>
@endif
