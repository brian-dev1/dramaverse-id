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

                {{--
                    Wilayah menentukan siapa yang melihat paket ini dan lewat
                    metode bayar mana ia ditagih. Paket wilayah Indonesia tidak
                    pernah muncul untuk orang yang memilih "bayar dari luar
                    Indonesia" di bot, dan sebaliknya.
                --}}
                <x-admin.field name="region" label="Wilayah pembayaran" type="select"
                               :options="$regionOptions"
                               :value="$record->exists ? $record->region?->value : 'ID'"
                               required
                               hint="Harus ada metode bayar aktif di wilayah yang sama, kalau tidak paket ini tidak akan ditawarkan." />

                <x-admin.field name="currency" label="Mata uang" type="select"
                               :options="$currencyOptions"
                               :value="$record->exists ? $record->currency : 'IDR'"
                               required />

                <x-admin.field name="price" label="Harga" type="text"
                               inputmode="decimal"
                               :value="$record->exists ? \App\Support\Uang::format($record->price, $record->currency) : null"
                               required
                               hint="Boleh isi bebas: 1500, 1.500, Rp 1.234, atau RM 14,90. Rupiah dibulatkan tanpa sen; mata uang lain menyimpan dua desimal." />

                <x-admin.field name="duration" label="Durasi (hari)" type="number"
                               :value="$record->duration" min="0" required
                               hint="Masa berlaku setelah pembayaran dikonfirmasi. Isi 0 untuk paket SEUMUR HIDUP — langganannya tidak pernah kedaluwarsa. Jangan pakai 36500: pengguna akan melihat '36500 hari', bukan 'Selamanya'." />

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
