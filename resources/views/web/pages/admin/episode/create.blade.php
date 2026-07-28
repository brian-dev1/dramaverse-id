@extends('web.layouts.admin')

@section('title', 'Tambah Episode')

@section('content')

<div class="admin-page">

    <x-web.admin.dashboard.admin-topbar />

    <div class="admin-page-header">

        <div>

            <h1>Tambah Episode</h1>

            <p>

                Tambahkan episode baru ke dalam drama.

            </p>

        </div>

        <div>

            <a
                href="{{ route('admin.episode.index') }}"
                class="btn btn-light"
            >

                <i class="ri-arrow-left-line"></i>

                Kembali

            </a>

        </div>

    </div>

    <form
        action="#"
        method="POST"
        enctype="multipart/form-data"
    >

        @csrf

        <x-web.admin.episode.episode-form-basic />

        <x-web.admin.episode.episode-form-video />

        <x-web.admin.episode.episode-form-subtitle />

        <x-web.admin.episode.episode-form-membership />

        <x-web.admin.episode.episode-form-telegram />

        <x-web.admin.episode.episode-form-publish />

    </form>

</div>

@endsection