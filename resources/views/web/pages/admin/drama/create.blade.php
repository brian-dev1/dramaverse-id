@extends('web.layouts.admin')

@section('title', 'Tambah Drama')

@section('content')

<div class="admin-page">

    <x-web.admin.dashboard.admin-topbar />

    <div class="admin-page-header">

        <div class="admin-page-title">

            <h1>Tambah Drama</h1>

            <p>
                Tambahkan drama baru ke dalam koleksi DramaVerse.
            </p>

        </div>

        <div class="admin-page-action">

            <a href="{{ route('admin.drama.index') }}" class="btn btn-light">

                <i class="ri-arrow-left-line"></i>

                Kembali

            </a>

        </div>

    </div>

    <form
        action="#"
        method="POST"
        enctype="multipart/form-data"
        class="admin-drama-create-form"
    >

        @csrf

        <x-web.admin.drama.admin-drama-form-basic />

        <x-web.admin.drama.admin-drama-form-media />

        <x-web.admin.drama.admin-drama-form-detail />

        <x-web.admin.drama.admin-drama-form-genre />

        <x-web.admin.drama.admin-drama-form-cast />

        <x-web.admin.drama.admin-drama-form-trailer />

        <x-web.admin.drama.admin-drama-form-membership />

        <x-web.admin.drama.admin-drama-form-telegram />

        <x-web.admin.drama.admin-drama-form-publish />

    </form>

</div>

@endsection