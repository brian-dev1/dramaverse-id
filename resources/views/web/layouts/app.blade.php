<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'DramaVerse ID') — DramaVerse ID</title>

    <meta name="description" content="@yield('description', 'Streaming drama Asia dengan subtitle Bahasa Indonesia.')">
    <meta name="theme-color" content="#0B0A10">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body>

    <div class="grain" aria-hidden="true"></div>

    <x-web.home.navbar />

    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif

    <main>
        @yield('content')
    </main>

    {{-- Promo membership hanya muncul di halaman yang meminta lewat @section('promo') --}}
    @yield('promo')

    <x-web.home.footer />

    <x-web.home.mobile-nav />

    @stack('scripts')

</body>
</html>
