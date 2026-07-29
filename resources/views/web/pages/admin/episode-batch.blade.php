@extends('web.layouts.admin')

@section('title', 'Tambah Episode Massal')

@section('content')

    <form method="POST" action="{{ route('admin.episode.batch.store') }}" class="admin-form">
        @csrf

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Rentang episode</h2>

                <x-admin.field name="drama_id" label="Drama" type="select" required
                               :value="old('drama_id', request('drama_id'))"
                               :options="$dramas->pluck('title', 'id')->all()"
                               data-next-numbers="{{ json_encode($nextNumbers) }}" />

                <x-admin.field name="start_from" label="Mulai dari nomor" type="number"
                               :value="old('start_from', 1)" min="1" required
                               data-auto-number
                               hint="Terisi otomatis dari nomor terakhir saat drama dipilih." />

                <x-admin.field name="count" label="Jumlah episode" type="number"
                               :value="old('count', 12)" min="1" max="100" required
                               hint="Maksimal 100 sekali jalan. Nomor yang sudah ada akan dilewati, bukan ditimpa." />

                <x-admin.field name="duration" label="Durasi (detik)" type="number"
                               :value="old('duration')" min="0"
                               hint="Diterapkan ke semua episode. Bisa diubah satu per satu nanti." />
            </section>

            <section class="form-card">
                <h2>Pengaturan</h2>

                <x-admin.field name="url_pattern" label="Pola URL video" :value="old('url_pattern')"
                               hint="Gunakan {n} untuk nomor, {nn} untuk dua digit. Contoh: https://cdn.contoh.com/judul/ep-{nn}.mp4" />

                <x-admin.field name="status" label="Status" type="select" required
                               :value="old('status', 'draft')"
                               :options="['draft' => 'Draf', 'published' => 'Terbit']" />

                <x-admin.field name="is_vip" label="Khusus VIP" type="checkbox"
                               :value="old('is_vip')" />
            </section>

        </div>

        <div class="form-actions">
            <a href="{{ route('admin.episode.index') }}" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary">Buat episode</button>
        </div>
    </form>

@endsection
