<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>

    <meta charset="utf-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <meta name="csrf-token"
          content="{{ csrf_token() }}">

    <title>
        @yield('title', 'DramaVerse ID')
    </title>

    <meta name="description"
          content="@yield('description','DramaVerse ID')">

    <meta name="robots"
          content="noindex,nofollow">

    <meta name="theme-color"
          content="#0B0A10">

    <link rel="preconnect"
          href="https://fonts.googleapis.com">

    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">

    @vite([
        'resources/css/app.css',
        'resources/css/web/theme.css',
        'resources/js/app.js',
        'resources/js/web/hero-slider.js',
    ])

    @stack('styles')

</head>

<body>

    {{-- Grain Background --}}
    <div class="grain"></div>

    {{-- Navbar --}}
    <x-web.navbar />

    {{-- Main Content --}}
    <main>

        @yield('content')

    </main>

    {{-- Footer --}}
    <x-web.footer />

    {{-- Toast --}}
    <x-web.toast />

    {{-- Modal --}}
    <x-web.modal />

    @stack('scripts')

</body>

</html>