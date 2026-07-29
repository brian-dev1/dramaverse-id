@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Paket</h2>

                <x-admin.field name="name" label="Nama paket" :value="$record->name" required />

                <x-admin.field name="slug" label="Slug" :value="$record->slug"
                               hint="Penanda tetap yang dipakai statistik. Jangan diubah setelah dipakai." />

                <x-admin.field name="price" label="Harga (Rupiah)" type="number" step="1000"
                               :value="$record->price" min="0" required />

                <x-admin.field name="duration" label="Durasi (hari)" type="number"
                               :value="$record->duration" min="1" required
                               hint="Masa berlaku setelah pembayaran dikonfirmasi." />

                <x-admin.field name="description" label="Deskripsi" type="textarea" :rows="3"
                               :value="$record->description" />
            </section>

            <section class="form-card">
                <h2>Tampilan</h2>

                <x-admin.field name="benefits" label="Benefit" type="textarea" :rows="6"
                               :value="$benefitsText"
                               hint="Satu benefit per baris. Ditampilkan sebagai daftar di halaman membership." />

                <x-admin.field name="badge" label="Badge" :value="$record->badge"
                               hint="Label kecil seperti 'Populer'. Kosongkan bila tidak perlu." />

                <x-admin.field name="sort_order" label="Urutan" type="number"
                               :value="$record->sort_order" min="0" />

                <x-admin.field name="is_active" label="Aktif" type="checkbox"
                               :value="$record->exists ? $record->is_active : true" />
            </section>

        </div>

    </x-admin.form>

@endsection
