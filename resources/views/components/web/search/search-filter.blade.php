<section class="web-filter">

<div class="web-container">

<select id="webGenre">

<option value="">

Semua Genre

</option>

@foreach($genres as $genre)

<option

value="{{ $genre->slug }}">

{{ $genre->name }}

</option>

@endforeach

</select>

<select id="webCountry">

<option value="">

Semua Negara

</option>

@foreach($countries as $country)

<option

value="{{ $country->slug }}">

{{ $country->name }}

</option>

@endforeach

</select>

</div>

</section>