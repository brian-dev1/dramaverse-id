@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode" multipart>

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Banner</h2>

                <x-admin.field name="title" label="Judul" :value="$record->title" required />
                <x-admin.field name="subtitle" label="Subjudul" type="textarea" :rows="3"
                               :value="$record->subtitle" />

                <x-admin.image-field name="image_file" label="Gambar" :current="$record->image"
                                     hint="Rasio lebar, mis. 1600×900. Maksimal 4 MB." />
            </section>

            <section class="form-card">
                <h2>Tautan dan jadwal</h2>

                <x-admin.field name="link" label="Tautan tujuan" :value="$record->link"
                               hint="Path relatif seperti /drama/judul-drama, atau URL penuh." />

                <x-admin.field name="button_text" label="Teks tombol" :value="$record->button_text"
                               hint="Kosongkan untuk memakai 'Tonton Sekarang'." />

                <x-admin.field name="position" label="Posisi" type="select"
                               :value="$record->position ?? 'hero'" :options="$positions" required />

                <x-admin.field name="sort_order" label="Urutan" type="number"
                               :value="$record->sort_order" min="0" />

                <x-admin.field name="start_at" label="Mulai tayang" type="datetime-local"
                               :value="$record->start_at?->format('Y-m-d\TH:i')" />

                <x-admin.field name="end_at" label="Berhenti tayang" type="datetime-local"
                               :value="$record->end_at?->format('Y-m-d\TH:i')" />

                <x-admin.field name="is_active" label="Aktif" type="checkbox"
                               :value="$record->exists ? $record->is_active : true" />
            </section>

        </div>

    </x-admin.form>

@endsection
