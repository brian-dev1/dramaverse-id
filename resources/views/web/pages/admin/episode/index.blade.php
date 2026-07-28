@extends('web.layouts.admin')

@section('title', 'Episode Management')

@section('content')

<div class="admin-page">

    <x-web.admin.dashboard.admin-topbar />

    <div class="admin-page-header">

        <div>

            <h1>Episode Management</h1>

            <p>

                Kelola seluruh episode drama.

            </p>

        </div>

        <div>

            <a
                href="{{ route('admin.episode.create') }}"
                class="btn btn-primary"
            >

                <i class="ri-add-line"></i>

                Tambah Episode

            </a>

        </div>

    </div>

    <x-web.admin.episode.episode-toolbar />

    <x-web.admin.episode.episode-filter />

    <x-web.admin.episode.episode-table />

</div>

@endsection