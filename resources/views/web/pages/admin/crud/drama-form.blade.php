@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode" multipart>

        <div class="form-grid">

            <section class="form-card form-main">
                <h2>Informasi dasar</h2>

                <x-admin.field name="title" label="Judul" :value="$record->title" required />
                <x-admin.field name="original_title" label="Judul asli" :value="$record->original_title"
                               hint="Judul dalam bahasa aslinya, opsional." />
                <x-admin.field name="slug" label="Slug" :value="$record->slug"
                               hint="Kosongkan untuk dibuat otomatis dari judul." />
                <x-admin.field name="synopsis" label="Sinopsis" type="textarea" :rows="6"
                               :value="$record->synopsis" />
            </section>

            <section class="form-card">
                <h2>Media</h2>

                <x-admin.image-field name="poster_file" label="Poster" :current="$record->poster"
                                     hint="Poster ini juga dipakai sebagai cover di beranda. Maksimal 4 MB." />

                <x-admin.field name="gradient" label="Gradien cadangan" type="select"
                               :value="$record->gradient ?? 'g1'"
                               :options="collect($gradients)->mapWithKeys(fn ($g) => [$g => strtoupper($g)])->all()"
                               hint="Dipakai bila poster belum diunggah." />

                <x-admin.field name="trailer_url" label="URL trailer" type="url" :value="$record->trailer_url" />
            </section>

            <section class="form-card">
                <h2>Klasifikasi</h2>

                <x-admin.field name="country_id" label="Negara" type="select"
                               :value="$record->country_id"
                               :options="$countries->pluck('name', 'id')->all()" />

                <x-admin.field name="genre_ids" label="Genre" type="multiselect"
                               :value="$selectedGenres"
                               :options="$genres->pluck('name', 'id')->all()" />

                <x-admin.field name="status" label="Status" type="select"
                               :value="$record->status ?? 'ongoing'" :options="$statuses" required />

                <x-admin.field name="release_year" label="Tahun rilis" type="number"
                               :value="$record->release_year" min="1950" max="{{ date('Y') + 5 }}" />

                <x-admin.field name="total_episode" label="Jumlah episode" type="number"
                               :value="$record->total_episode" min="0"
                               hint="Diperbarui otomatis saat episode ditambah." />

                <x-admin.field name="duration" label="Durasi per episode (menit)" type="number"
                               :value="$record->duration" min="0" />

                <x-admin.field name="rating" label="Rating" type="number" step="0.1"
                               :value="$record->rating" min="0" max="10" />
            </section>

            <section class="form-card">
                <h2>Publikasi</h2>

                <x-admin.field name="is_vip" label="Khusus VIP" type="checkbox"
                               :value="$record->is_vip" hint="Hanya anggota VIP yang bisa menonton" />

                <x-admin.field name="is_featured" label="Unggulan" type="checkbox"
                               :value="$record->is_featured" hint="Tampil di hero beranda" />

                <x-admin.field name="is_trending" label="Trending" type="checkbox"
                               :value="$record->is_trending" hint="Tampil di deretan trending" />

                <x-admin.field name="trending_score" label="Skor trending" type="number"
                               :value="$record->trending_score" min="0"
                               hint="Semakin besar semakin atas." />

                <x-admin.field name="published_at" label="Tanggal terbit" type="datetime-local"
                               :value="$record->published_at?->format('Y-m-d\TH:i')"
                               hint="Kosongkan untuk menyimpan sebagai draf." />
            </section>

        </div>

    </x-admin.form>

@endsection
