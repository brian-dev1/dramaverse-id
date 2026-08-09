<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', setting('site_tagline', 'Drama Asia')) — {{ setting('site_name', 'DramaVerse ID') }}</title>

    <meta name="description" content="@yield('description', setting('site_description', 'Streaming drama Asia dengan subtitle Bahasa Indonesia.'))">

    @if ($favicon = setting('favicon'))
        <link rel="icon" href="{{ asset('storage/'.$favicon) }}">
    @endif

    @if ($ogImage = setting('og_image'))
        <meta property="og:image" content="{{ asset('storage/'.$ogImage) }}">
    @endif
    <meta name="theme-color" content="#140A06">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Font baru: Outfit (judul) + Plus Jakarta Sans (teks).
         Hanya 2 keluarga, dimuat non-blocking. --}}
    <link rel="stylesheet"
          media="print" onload="this.media='all'"
          href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <noscript>
        <link rel="stylesheet"
              href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    </noscript>

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

    <x-web.home.mobile-nav />

    @include('web.partials.miniapp')

    @stack('scripts')

</body>
</html>
