@extends('web.layouts.admin')

@section('title', 'Tambah Episode Massal')

@php
    /*
    | Rentang yang dirender saat halaman dibuka. Setelah validasi gagal,
    | yang ditampilkan adalah isian admin sendiri — bukan contoh bawaan,
    | supaya tidak ada pekerjaan yang hilang saat form dikembalikan.
    */
    $rentang = old('ranges', [
        ['from' => 1, 'to' => 1,  'is_vip' => 0, 'status' => 'published'],
        ['from' => 2, 'to' => 5,  'is_vip' => 1, 'status' => 'published'],
    ]);
@endphp

@section('content')

    <form method="POST" action="{{ route('admin.episode.batch.store') }}" class="admin-form">
        @csrf

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Drama</h2>

                <x-admin.field name="drama_id" label="Drama" type="select" required
                               :value="old('drama_id', $dramaId)"
                               :options="$dramas->pluck('title', 'id')->all()"
                               data-next-numbers="{{ json_encode($nextNumbers) }}" />

                <h2 style="margin-top:1.25rem">Rentang episode</h2>

                <p class="field-hint">
                    Satu baris = satu rentang. Contoh: episode 1 gratis, lalu 2–5 VIP.
                    Nomor yang sudah ada akan dilewati, bukan ditimpa. Maksimal 300 episode sekali jalan.
                </p>

                @error('ranges')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <div class="table-wrap">
                    <table class="data-table" data-range-table>
                        <thead>
                            <tr>
                                <th style="width:22%">Dari nomor</th>
                                <th style="width:22%">Sampai nomor</th>
                                <th style="width:20%">Akses</th>
                                <th style="width:24%">Status</th>
                                <th style="width:12%"></th>
                            </tr>
                        </thead>
                        <tbody data-range-body>
                            @foreach ($rentang as $i => $baris)
                                <tr data-range-row>
                                    <td>
                                        <input type="number" min="1" max="9999" required class="control control-sm"
                                               name="ranges[{{ $i }}][from]"
                                               value="{{ $baris['from'] ?? '' }}"
                                               @if ($i === 0) data-auto-number @endif>
                                        @error("ranges.{$i}.from")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </td>

                                    <td>
                                        <input type="number" min="1" max="9999" required class="control control-sm"
                                               name="ranges[{{ $i }}][to]"
                                               value="{{ $baris['to'] ?? '' }}">
                                        @error("ranges.{$i}.to")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </td>

                                    <td>
                                        <select name="ranges[{{ $i }}][is_vip]" class="control control-sm">
                                            <option value="0" @selected(! ($baris['is_vip'] ?? false))>Gratis</option>
                                            <option value="1" @selected((bool) ($baris['is_vip'] ?? false))>VIP</option>
                                        </select>
                                    </td>

                                    <td>
                                        <select name="ranges[{{ $i }}][status]" class="control control-sm">
                                            <option value="published" @selected(($baris['status'] ?? 'published') === 'published')>Terbit</option>
                                            <option value="draft" @selected(($baris['status'] ?? '') === 'draft')>Draf</option>
                                        </select>
                                    </td>

                                    <td class="col-actions">
                                        <button type="button" class="btn btn-ghost btn-sm" data-range-remove>Hapus</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="button" class="btn btn-ghost btn-sm" data-range-add>
                    <x-web.home.icon name="plus" :size="14" />
                    Tambah rentang
                </button>
            </section>

            <section class="form-card">
                <h2>Berlaku untuk semua rentang</h2>

                <x-admin.field name="duration" label="Durasi (detik)" type="number"
                               :value="old('duration')" min="0"
                               hint="Boleh dikosongkan. Bisa diubah satu per satu nanti." />

                <x-admin.field name="url_pattern" label="Pola URL video" :value="old('url_pattern')"
                               hint="Gunakan {n} untuk nomor, {nn} untuk dua digit. Contoh: https://cdn.contoh.com/judul/ep-{nn}.mp4" />
            </section>

        </div>

        <div class="form-actions">
            <a href="{{ route('admin.episode.index', ['drama_id' => old('drama_id', $dramaId)]) }}" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary">Buat episode</button>
        </div>
    </form>

@endsection
