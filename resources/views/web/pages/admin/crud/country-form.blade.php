@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Negara</h2>

                <x-admin.field name="name" label="Nama" :value="$record->name" required />

                <x-admin.field name="code" label="Kode ISO" :value="$record->code" maxlength="2"
                               hint="Dua huruf, mis. KR. Dipakai sebagai penanda visual." />

                <x-admin.field name="slug" label="Slug" :value="$record->slug"
                               hint="Kosongkan untuk dibuat otomatis." />

                <x-admin.field name="description" label="Deskripsi" type="textarea" :rows="3"
                               :value="$record->description" />
            </section>

            <section class="form-card">
                <h2>Tampilan</h2>

                <x-admin.field name="sort_order" label="Urutan" type="number"
                               :value="$record->sort_order" min="0" />

                <x-admin.field name="is_active" label="Aktif" type="checkbox"
                               :value="$record->exists ? $record->is_active : true" />
            </section>

        </div>

    </x-admin.form>

@endsection
