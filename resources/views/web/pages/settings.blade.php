@extends('web.layouts.app')

@section('title', 'Pengaturan')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Pengaturan</h1>
        <p class="page-subtitle">Kelola tampilan nama dan akun Telegram Anda.</p>
    </section>

    <section class="section section-pad">

        <form method="POST" action="{{ route('web.settings.update') }}" class="settings-form">
            @csrf
            @method('PUT')

            <label for="name">Nama Tampilan</label>
            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                   class="search-input" required maxlength="100">

            @error('name')
                <p class="form-error">{{ $message }}</p>
            @enderror

            <dl class="settings-meta">
                <dt>Telegram</dt>
                <dd>{{ $user->telegram_username ? '@'.$user->telegram_username : 'Belum tertaut' }}</dd>

                <dt>ID Telegram</dt>
                <dd>{{ $user->telegram_id ?? '—' }}</dd>

                <dt>Terakhir masuk</dt>
                <dd>{{ $user->last_login_at?->translatedFormat('d F Y, H:i') ?? '—' }}</dd>
            </dl>

            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>

    </section>

@endsection
