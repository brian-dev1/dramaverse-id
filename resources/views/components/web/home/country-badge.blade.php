@props(['country'])

{{--
    Penanda negara berbasis kode dua huruf, bukan emoji bendera.
    Emoji bendera tidak dirender di Windows dan sebagian Android —
    yang muncul justru dua huruf tanpa gaya, terlihat seperti kesalahan.
--}}

<span class="country-badge">{{ $country->code ?? mb_substr($country->name, 0, 2) }}</span>
