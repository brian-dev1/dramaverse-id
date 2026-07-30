@extends('web.layouts.admin')

@section('title', 'Menu Telegram')

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>Pratayang menu</h2>
            <span class="panel-meta">Persis seperti yang dilihat pengguna setelah menekan Start</span>
        </div>

        <div class="detail-body-admin">
            @forelse ($preview as $baris)
                <div class="radio-row">
                    @foreach ($baris as $tombol)
                        <span class="badge badge-on">{{ $tombol['text'] }}</span>
                    @endforeach
                </div>
            @empty
                <p class="page-subtitle">Belum ada tombol aktif. Bot akan memakai susunan bawaan.</p>
            @endforelse
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Susunan tombol</h2>
            <span class="panel-meta">
                Baris menentukan urutan ke bawah, posisi menentukan urutan di dalam baris.
                Dua tombol dengan baris yang sama akan berdampingan.
            </span>
        </div>

        @if ($menus->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Belum ada tombol tersimpan. Bot tetap menampilkan menu bawaan.
                    Tekan <strong>Pulihkan bawaan</strong> di bawah untuk mulai mengaturnya.
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Perbuatan</th>
                            <th>URL</th>
                            <th>Baris</th>
                            <th>Posisi</th>
                            <th>Aktif</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($menus as $menu)
                            <tr>
                                <td>
                                    <input type="text" class="control control-sm" form="menu-form"
                                           name="menus[{{ $menu->id }}][label]"
                                           value="{{ old("menus.{$menu->id}.label", $menu->label) }}"
                                           maxlength="64" required>
                                </td>
                                <td>
                                    <select class="control control-sm" form="menu-form"
                                            name="menus[{{ $menu->id }}][action]" required>
                                        @foreach ($actions as $nilai => $label)
                                            <option value="{{ $nilai }}"
                                                @selected(old("menus.{$menu->id}.action", $menu->action->value) === $nilai)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="url" class="control control-sm" form="menu-form"
                                           name="menus[{{ $menu->id }}][url]"
                                           value="{{ old("menus.{$menu->id}.url", $menu->url) }}"
                                           placeholder="hanya untuk Tautan bebas">
                                </td>
                                <td>
                                    <input type="number" class="control control-sm" form="menu-form"
                                           name="menus[{{ $menu->id }}][row]"
                                           value="{{ old("menus.{$menu->id}.row", $menu->row) }}"
                                           min="1" max="20" required>
                                </td>
                                <td>
                                    <input type="number" class="control control-sm" form="menu-form"
                                           name="menus[{{ $menu->id }}][position]"
                                           value="{{ old("menus.{$menu->id}.position", $menu->position) }}"
                                           min="1" max="8" required>
                                </td>
                                <td>
                                    <input type="hidden" form="menu-form"
                                           name="menus[{{ $menu->id }}][is_active]" value="0">
                                    <input type="checkbox" form="menu-form"
                                           name="menus[{{ $menu->id }}][is_active]" value="1"
                                           @checked(old("menus.{$menu->id}.is_active", $menu->is_active))>
                                </td>
                                <td class="col-actions">
                                    <button type="submit" class="btn btn-danger btn-icon"
                                            form="hapus-{{ $menu->id }}"
                                            onclick="return confirm('Hapus tombol {{ $menu->label }}?')">
                                        <x-web.home.icon name="trash" :size="14" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{--
            Form penyimpanan dan form hapus sengaja berada DI LUAR tabel.
            Form di dalam form dibuang parser HTML, dan tombolnya akan
            mengirim ke action yang salah. Penghubungnya atribut `form`.
        --}}
        <form method="POST" action="{{ route('admin.telegram-menu.update') }}"
              id="menu-form" class="admin-form">
            @csrf
            @method('PUT')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <x-web.home.icon name="check" :size="14" />
                    Simpan susunan
                </button>
            </div>
        </form>

        @foreach ($menus as $menu)
            <form method="POST" action="{{ route('admin.telegram-menu.destroy', $menu->id) }}"
                  id="hapus-{{ $menu->id }}" class="inline-form">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    </section>

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head"><h2>Tambah tombol</h2></div>

            <form method="POST" action="{{ route('admin.telegram-menu.store') }}" class="admin-form">
                @csrf

                <x-admin.field name="label" label="Label" :value="old('label')" required
                               hint="Teks yang dilihat pengguna. Emoji boleh dipakai di sini." />

                <x-admin.field name="action" label="Perbuatan" type="select" required
                               :value="old('action')" :options="$actions" />

                <x-admin.field name="url" label="URL" :value="old('url')"
                               hint="Hanya diisi bila perbuatannya Tautan bebas." />

                <x-admin.field name="row" label="Baris" type="number" required
                               :value="old('row', ($menus->max('row') ?? 0) + 1)" />

                <x-admin.field name="position" label="Posisi" type="number" required
                               :value="old('position', 1)" />

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <x-web.home.icon name="plus" :size="14" />
                        Tambah
                    </button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Pulihkan bawaan</h2></div>

            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Mengembalikan tombol bawaan yang hilang. Tombol yang sudah Anda ubah
                    tidak disentuh, dan tidak ada yang dihapus.
                </p>

                <form method="POST" action="{{ route('admin.telegram-menu.reset') }}" class="admin-form">
                    @csrf

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <x-web.home.icon name="restore" :size="14" />
                            Pulihkan bawaan
                        </button>
                    </div>
                </form>
            </div>
        </section>

    </div>

@endsection
