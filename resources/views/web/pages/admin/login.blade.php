<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Admin — DramaVerse ID</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body>

    <div class="grain" aria-hidden="true"></div>

    <main class="auth-wrap">
        <form method="POST" action="{{ route('admin.login.attempt') }}" class="auth-card">
            @csrf

            <div class="logo">DramaVerse<span class="dot"></span><span class="id">ADMIN</span></div>

            <p class="page-subtitle">Masuk untuk mengelola katalog dan pengguna.</p>

            @if ($errors->any())
                <p class="form-error">{{ $errors->first() }}</p>
            @endif

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="search-input" required autofocus autocomplete="username">

            <label for="password">Kata Sandi</label>
            <input type="password" id="password" name="password"
                   class="search-input" required autocomplete="current-password">

            <label class="search-check">
                <input type="checkbox" name="remember" value="1"> Ingat saya
            </label>

            <button type="submit" class="btn btn-primary">Masuk</button>

            <a href="{{ route('web.home') }}" class="see-all"><x-web.home.icon name="arrow-left" :size="13" /> Kembali ke situs</a>
        </form>
    </main>

</body>
</html>
