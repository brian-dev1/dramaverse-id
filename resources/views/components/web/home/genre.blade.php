@props(['genres'])

{{--
    Dulu blok besar di dasar beranda dengan judul bagian sendiri. Sekarang
    satu baris chip yang digeser ke samping, duduk tepat di bawah kolom cari:
    di situ ia berfungsi sebagai penyaring yang terlihat sebelum orang mulai
    menggulir, bukan sebagai catatan kaki yang baru ditemukan setelah seluruh
    katalog terlewati.

    Judul bagiannya sengaja tidak dibawa serta — sebuah baris chip genre
    tidak perlu diberi tahu bahwa ia berisi genre.
--}}
@if ($genres->isNotEmpty())
    <nav class="dv-chiprow" aria-label="Jelajahi genre">
        @foreach ($genres as $genre)
            <a href="{{ route('web.genre.show', $genre->slug) }}" class="dv-chip">{{ $genre->name }}</a>
        @endforeach

        <a href="{{ route('web.genre.index') }}" class="dv-chip dv-chip-all">Semua genre →</a>
    </nav>
@endif
