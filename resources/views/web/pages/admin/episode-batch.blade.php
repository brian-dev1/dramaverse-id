@extends('web.layouts.admin')

@section('title', 'Tambah Episode Massal')

@php
    /*
    | Rentang yang dirender saat halaman dibuka. Setelah validasi gagal,
    | yang ditampilkan adalah isian admin sendiri — bukan contoh bawaan,
    | supaya tidak ada pekerjaan yang hilang saat form dikembalikan.
    |
    | Nomor awal disiapkan di server, bukan menunggu admin menyentuh dropdown.
    |
    | Pengisi nomor otomatis di sisi klien hanya berjalan pada event `change`.
    | Padahal jalur yang paling sering dipakai — tombol + di daftar drama —
    | datang dengan drama SUDAH terpilih, jadi event itu tidak pernah terjadi
    | dan form tampil dengan contoh 1–1 dan 2–5. Untuk drama yang episodenya
    | sudah terisi, seluruh rentang itu dilewati sebagai duplikat: admin
    | menekan Simpan dan tidak ada satu pun episode yang bertambah.
    |
    | Karena itu nomor berikutnya dihitung di sini. Drama yang belum punya
    | episode tetap mendapat contoh dua baris (episode 1 gratis sebagai umpan,
    | sisanya VIP) — pola itu memang berguna untuk drama baru.
    */
    $mulai = $dramaId ? (int) ($nextNumbers[$dramaId] ?? 1) : 1;

    $bawaan = $mulai > 1
        ? [['from' => $mulai, 'to' => $mulai, 'is_vip' => 1, 'status' => 'published']]
        : [
            ['from' => 1, 'to' => 1, 'is_vip' => 0, 'status' => 'published'],
            ['from' => 2, 'to' => 5, 'is_vip' => 1, 'status' => 'published'],
        ];

    $rentang = old('ranges', $bawaan);

    // Alamat daftar yang mengirim admin ke sini, dititipkan lagi ke server
    // supaya Simpan mengembalikannya ke halaman itu — bukan ke halaman 1.
    $kembali = \App\Support\AdminReturnUrl::current();

    $batal = $kembali
        ?? route('admin.episode.index', ['drama_id' => old('drama_id', $dramaId)]);
@endphp

@section('content')

    <form method="POST" action="{{ route('admin.episode.batch.store') }}" class="admin-form">
        @csrf

        @if ($kembali)
            <input type="hidden" name="{{ \App\Support\AdminReturnUrl::KEY }}" value="{{ $kembali }}">
        @endif

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

                <x-admin.field name="url_pattern" label="Pola URL video" :value="old('url_pattern')"
                               hint="Gunakan {n} untuk nomor, {nn} untuk dua digit. Contoh: https://cdn.contoh.com/judul/ep-{nn}.mp4" />
            </section>

        </div>

        <div class="form-actions">
            <a href="{{ $batal }}" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary">Buat episode</button>
        </div>
    </form>

@endsection
