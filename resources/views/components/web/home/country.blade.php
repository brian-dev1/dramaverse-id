@props(['countries'])

{{-- Sepasang dengan genre.blade.php — lihat catatan di sana. --}}
@if ($countries->isNotEmpty())
    <nav class="dv-chiprow" aria-label="Jelajahi negara">
        @foreach ($countries as $country)
            <a href="{{ route('web.country.show', $country->slug) }}" class="dv-chip">
                <x-web.home.country-badge :country="$country" /> {{ $country->name }}
            </a>
        @endforeach

        <a href="{{ route('web.country.index') }}" class="dv-chip dv-chip-all">Semua negara →</a>
    </nav>
@endif
