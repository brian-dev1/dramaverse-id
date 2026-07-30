@extends('web.layouts.admin')

@section('title', 'Menu Telegram')

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>Pratayang menu</h2>
            <span class="panel-meta">Persis seperti yang dilihat pengguna setelah menekan Start</span>
        </div>

        <div class="detail-body-admin">
            <div class="tm-preview">
                @forelse ($preview as $baris)
                    <div class="tm-preview-row">
                        @foreach ($baris as $tombol)
                            <span class="tm-preview-btn">{{ $tombol['text'] }}</span>
                        @endforeach
                    </div>
                @empty
                    <p class="page-subtitle">
                        Belum ada tombol aktif. Bot akan memakai susunan bawaan.
                    </p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Susunan tombol</h2>
            <span class="panel-meta">
                Baris menentukan urutan ke bawah, posisi menentukan urutan di dalam baris
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
                <table class="data-table tm-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Perbuatan</th>
                            <th>URL</th>
                            <th class="tm-col-num">Baris</th>
                            <th class="tm-col-num">Posisi</th>
                            <th class="tm-col-switch">Aktif</th>
                            <th class="col-actions">Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($menus as $menu)
                            @php $terkunci = $menu->action->isLocked(); @endphp

                            <tr>
                                <td>
                                    <input type="text" class="control control-sm tm-label" form="menu-form"
                                           name="menus[{{ $menu->id }}][label]"
                                           value="{{ old("menus.{$menu->id}.label", $menu->label) }}"
                                           maxlength="64" required>
                                </td>

                                <td>
                                    @if ($terkunci)
                                        <input type="hidden" form="menu-form"
                                               name="menus[{{ $menu->id }}][action]"
                                               value="{{ $menu->action->value }}">

                                        <span class="tm-fixed">{{ $menu->action->label() }}</span>
                                        <span class="badge tm-lock">Permanen</span>
                                    @else
                                        <select class="control control-sm tm-select" form="menu-form"
                                                name="menus[{{ $menu->id }}][action]" required
                                                data-tm-action>
                                            @foreach ($actions as $nilai => $label)
                                                <option value="{{ $nilai }}"
                                                    @selected(old("menus.{$menu->id}.action", $menu->action->value) === $nilai)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>

                                <td>
                                    @if ($terkunci)
                                        <span class="tm-fixed tm-muted">dibuat otomatis per pengguna</span>
                                    @else
                                        <input type="url" class="control control-sm tm-url" form="menu-form"
                                               name="menus[{{ $menu->id }}][url]"
                                               value="{{ old("menus.{$menu->id}.url", $menu->url) }}"
                                               placeholder="hanya untuk Tautan bebas">
                                    @endif
                                </td>

                                <td class="tm-col-num">
                                    <input type="number" class="control control-sm tm-num" form="menu-form"
                                           name="menus[{{ $menu->id }}][row]"
                                           value="{{ old("menus.{$menu->id}.row", $menu->row) }}"
                                           min="1" max="20" required>
                                </td>

                                <td class="tm-col-num">
                                    <input type="number" class="control control-sm tm-num" form="menu-form"
                                           name="menus[{{ $menu->id }}][position]"
                                           value="{{ old("menus.{$menu->id}.position", $menu->position) }}"
                                           min="1" max="8" required>
                                </td>

                                <td class="tm-col-switch">
                                    @if ($terkunci)
                                        <span class="badge badge-on">Selalu aktif</span>
                                    @else
                                        <input type="hidden" form="menu-form"
                                               name="menus[{{ $menu->id }}][is_active]" value="0">

                                        <label class="switch tm-switch">
                                            <input type="checkbox" form="menu-form"
                                                   name="menus[{{ $menu->id }}][is_active]" value="1"
                                                   @checked(old("menus.{$menu->id}.is_active", $menu->is_active))>
                                            <span class="switch-track"><span class="switch-thumb"></span></span>
                                        </label>
                                    @endif
                                </td>

                                <td class="col-actions">
                                    @if ($terkunci)
                                        <span class="tm-muted">—</span>
                                    @else
                                        <button type="submit" class="btn btn-danger btn-icon"
                                                form="hapus-{{ $menu->id }}"
                                                title="Hapus tombol {{ $menu->label }}"
                                                onclick="return confirm('Hapus tombol {{ $menu->label }}?')">
                                            <x-web.home.icon name="close" :size="14" />
                                        </button>
                                    @endif
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
              id="menu-form" class="admin-form tm-save">
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
            @unless ($menu->action->isLocked())
                <form method="POST" action="{{ route('admin.telegram-menu.destroy', $menu->id) }}"
                      id="hapus-{{ $menu->id }}" class="inline-form">
                    @csrf
                    @method('DELETE')
                </form>
            @endunless
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

                <div class="form-grid-narrow">
                    <x-admin.field name="row" label="Baris" type="number" required
                                   :value="old('row', ($menus->max('row') ?? 0) + 1)" />

                    <x-admin.field name="position" label="Posisi" type="number" required
                                   :value="old('position', 1)" />
                </div>

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
