@props([
    'countries' => collect(),
    'activeCountry' => null,
])

<section class="section section-pad">

    <div class="section-head">

        <div class="section-title">

            🌏 Berdasarkan Negara

        </div>

    </div>

    <div class="country-grid">

        @forelse($countries as $country)

            <button
                class="country-card {{ $activeCountry == $country->slug ? 'active' : '' }}"
                data-country="{{ $country->slug }}">

                <div class="country-flag">

                    <img
                        src="{{ asset($country->flag) }}"
                        alt="{{ $country->name }}">

                </div>

                <div class="country-info">

                    <div class="country-name">

                        {{ $country->name }}

                    </div>

                    <div class="country-count">

                        {{ number_format($country->dramas_count) }} Drama

                    </div>

                </div>

            </button>

        @empty

            <div class="empty-state">

                Belum ada negara tersedia.

            </div>

        @endforelse

    </div>

</section>