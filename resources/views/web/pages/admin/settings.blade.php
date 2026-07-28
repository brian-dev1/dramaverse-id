@extends('web.layouts.admin')

@section('title', 'Pengaturan')

@section('content')

    <form method="POST" action="{{ route('admin.settings.update') }}" class="settings-form">
        @csrf
        @method('PUT')

        @forelse ($settings as $setting)
            <label for="setting-{{ $setting->key }}">{{ $setting->key }}</label>
            <input type="text" id="setting-{{ $setting->key }}"
                   name="settings[{{ $setting->key }}]"
                   value="{{ old('settings.'.$setting->key, $setting->value) }}"
                   class="search-input">
        @empty
            <p class="page-subtitle">Belum ada pengaturan tersimpan.</p>
        @endforelse

        @if ($settings->isNotEmpty())
            <button type="submit" class="btn btn-primary">Simpan</button>
        @endif
    </form>

@endsection
