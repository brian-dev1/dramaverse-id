@props([
    'title'   => 'Belum ada apa-apa di sini',
    'message' => 'Coba jelajahi katalog untuk menemukan drama baru.',
    'href'    => null,
    'action'  => 'Jelajahi Katalog',
])

<div class="empty-state">
    <h3>{{ $title }}</h3>
    <p>{{ $message }}</p>

    @if ($href)
        <a href="{{ $href }}" class="btn btn-primary">{{ $action }}</a>
    @endif
</div>
