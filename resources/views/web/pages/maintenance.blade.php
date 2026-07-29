<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Sedang Berbenah — {{ setting('site_name', 'DramaVerse ID') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body>

    <div class="grain" aria-hidden="true"></div>

    <main class="auth-wrap">
        <div class="auth-card" style="text-align:center;">
            <div class="logo" style="justify-content:center;">
                DramaVerse<span class="dot"></span><span class="id">ID</span>
            </div>

            <h1 class="page-title" style="font-size:26px;">Sedang berbenah</h1>

            <p class="page-subtitle">{{ $message }}</p>
        </div>
    </main>

</body>
</html>
