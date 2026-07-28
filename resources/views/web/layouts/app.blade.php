<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}">

    <title>

        @yield('title', 'DramaVerse')

    </title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

</head>

<body>

    <div class="app">

        {{-- Navbar --}}
        <x-web.home.navbar />

        {{-- Main --}}
        <main>

            @yield('content')

        </main>

        {{-- Footer --}}
        <x-web.home.footer />

    </div>

    @stack('scripts')

</body>

</html>