@extends('web.layouts.admin')

@section('title', $title)

@php
    // Pembangun tautan pengurutan. Seluruh penyaring yang sedang aktif ikut
    // dibawa — tanpa itu, mengurutkan akan membuang hasil pencarian yang baru
    // saja diketik admin.
    $sortLink = function (string $kolom) use ($filters) {
        $arahBaru = ($filters['sort'] === $kolom && $filters['dir'] === 'asc') ? 'desc' : 'asc';

        return route('admin.files.index', array_merge(
            array_filter($filters, fn ($v) => $v !== '' && $v !== null),
            ['sort' => $kolom, 'dir' => $arahBaru]
        ));
    };
@endphp

@section('content')

    {{--
        Form penyaring memakai method GET dan TIDAK melingkupi tabel. Itu
        disengaja: form yang membungkus tabel membuat form tombol per baris
        menjadi bersarang, dan parser HTML membuang tag <form> bersarang —
        bug yang tercatat di STATUS.md untuk modul-modul CRUD.
    --}}
    <form method="GET" class="admin-toolbar">
        <div class="toolbar-search">
            <x-web.home.icon name="search" :size="15" />
            <input type="search" name="q" value="{{ $filters['q'] }}"
                   placeholder="Cari nama berkas, object key, atau judul drama..."
                   class="control">
        </div>

        <select name="source" class="control control-sm">
            <option value="">Sumber: semua</option>
            @foreach ($sources as $value => $label)
                <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="kind" class="control control-sm">
            <option value="">Jenis: semua</option>
            @foreach ($kinds as $value => $label)
                <option value="{{ $value }}" @selected($filters['kind'] === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="provider" class="control control-sm">
            <option value="">Provider: semua</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}"
                        @selected((string) $filters['provider'] === (string) $provider->id)>
                    {{ $provider->name }}
                </option>
            @endforeach
        </select>

        <select name="ext" class="control control-sm">
            <option value="">Ekstensi: semua</option>
            @foreach ($extensions as $ext)
                <option value="{{ $ext }}" @selected($filters['ext'] === $ext)>{{ $ext }}</option>
            @endforeach
        </select>

        {{-- Pengurutan ikut terbawa saat penyaring diterapkan. --}}
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if ($anyFilter)
            <a href="{{ route('admin.files.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif

        <div class="toolbar-actions">
            <a href="{{ route('admin.batch.form') }}" class="btn btn-primary btn-sm">
                <x-web.home.icon name="plus" :size="14" />
                Batch Upload
            </a>
        </div>
    </form>

    @if ($files->isEmpty())

        <div class="empty-state">
            <h3>Belum ada berkas</h3>
            <p>
                @if ($anyFilter)
                    Tidak ada berkas yang cocok dengan penyaring Anda.
                @else
                    Daftar ini terisi sendiri begitu ada video part atau aset drama
                    yang diunggah.
                @endif
            </p>

            <a href="{{ route('admin.batch.form') }}" class="btn btn-primary">
                Unggah berkas
            </a>
        </div>

    @else

        <div class="table-wrap">
            <table class="data-table" data-file-manager
                   data-show-url="{{ route('admin.files.show', ['source' => 'episode_video', 'id' => 0]) }}">
                <thead>
                    <tr>
                        <th><a href="{{ $sortLink('original_filename') }}" class="th-sort">Berkas</a></th>
                        <th>Jenis</th>
                        <th><a href="{{ $sortLink('owner_title') }}" class="th-sort">Milik</a></th>
                        <th>Provider</th>
                        <th><a href="{{ $sortLink('size') }}" class="th-sort">Ukuran</a></th>
                        <th><a href="{{ $sortLink('uploaded_at') }}" class="th-sort">Diunggah</a></th>
                        <th>Status</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($files as $file)
                        <tr data-file="{{ $file['ref'] }}">

                            <td>
                                <span class="fm-name">{{ $file['stored_filename'] }}</span>
                                <span class="asset-sub">asli: {{ $file['original_filename'] }}</span>
                                <span class="asset-sub fm-key">{{ $file['object_key'] }}</span>
                            </td>

                            <td>
                                <x-web.home.icon :name="$file['icon']" :size="14" />
                                {{ $file['kind_label'] }}
                                <span class="asset-sub">{{ $file['source_label'] }}</span>
                            </td>

                            <td>
                                @if ($file['owner_title'])
                                    {{ $file['owner_title'] }}
                                    @if ($file['episode_number'] !== null)
                                        <span class="asset-sub">
                                            Part {{ str_pad((string) $file['episode_number'], 2, '0', STR_PAD_LEFT) }}
                                        </span>
                                    @endif
                                @else
                                    <span class="cell-empty">Pemiliknya sudah dihapus</span>
                                @endif
                            </td>

                            <td>
                                {{ $file['provider_name'] ?: '—' }}
                                @unless ($file['reachable'])
                                    <span class="asset-sub monitor-problem">
                                        provider sudah dihapus permanen
                                    </span>
                                @endunless
                            </td>

                            <td>{{ $file['size_human'] }}</td>

                            <td>{{ \App\Support\Waktu::ringkas($file['uploaded_at']) }}</td>

                            <td>
                                <span class="badge badge-status {{ $file['reachable'] ? 'badge-on' : 'badge-off' }}">
                                    {{ $file['reachable'] ? 'Terhubung' : 'Tak terjangkau' }}
                                </span>
                            </td>

                            <td class="col-actions">

                                {{--
                                    Berkas yang providernya sudah dihapus permanen
                                    tidak diberi satu pun tombol aksi. Aplikasi
                                    kehilangan kredensialnya, jadi setiap operasi
                                    pasti gagal — tombol yang pasti gagal lebih
                                    buruk daripada tidak ada tombol.

                                    Barisnya tetap ditampilkan, bukan disembunyikan:
                                    berkasnya masih ada di bucket dan masih
                                    menghabiskan ruang berbayar.
                                --}}
                                @if ($file['reachable'])

                                    <button type="button" class="btn-icon" data-file-detail="{{ $file['ref'] }}"
                                            title="Rincian dan pratayang" aria-label="Rincian dan pratayang">
                                        <x-web.home.icon name="search" :size="15" />
                                    </button>

                                    <a href="{{ route('admin.files.download', ['source' => $file['source_key'], 'id' => $file['source_id']]) }}"
                                       class="btn-icon" title="Unduh" aria-label="Unduh">
                                        <x-web.home.icon name="arrow-right" :size="15" />
                                    </a>

                                    <button type="button" class="btn-icon"
                                            data-file-rename="{{ $file['ref'] }}"
                                            data-file-name="{{ $file['basename'] }}"
                                            title="Ganti nama" aria-label="Ganti nama">
                                        <x-web.home.icon name="edit" :size="15" />
                                    </button>

                                    <button type="button" class="btn-icon"
                                            data-file-move="{{ $file['ref'] }}"
                                            data-file-dir="{{ $file['directory'] }}"
                                            title="Pindahkan" aria-label="Pindahkan">
                                        <x-web.home.icon name="sort" :size="15" />
                                    </button>

                                    <x-admin.confirm
                                        :action="route('admin.files.destroy', ['source' => $file['source_key'], 'id' => $file['source_id']])"
                                        title="Hapus berkas ini dari penyimpanan?"
                                        :message="'Berkas dihapus dari bucket DAN barisnya dihapus dari database. '.$file['source']?->deleteWarning()" />

                                @else
                                    <span class="cell-empty">tidak ada aksi</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">{{ $files->links() }}</div>

        {{--
            Panel rincian satu berkas, diisi JS.

            Menetap di halaman, bukan toast: pratayang gambar dan URL yang
            harus disalin keduanya perlu dibaca dan dipakai, bukan dilihat
            sekilas lalu hilang.
        --}}
        <section class="panel fm-panel" data-file-panel hidden>
            <div class="panel-head">
                <h2 data-file-title>Rincian berkas</h2>
                <button type="button" class="btn-icon" data-file-close
                        title="Tutup" aria-label="Tutup">
                    <x-web.home.icon name="close" :size="15" />
                </button>
            </div>

            <div class="detail-body-admin">

                <div class="fm-preview" data-file-preview hidden>
                    <img src="" alt="Pratayang berkas" data-file-image class="fm-preview-img">
                </div>

                <p class="panel-meta" data-file-meta></p>

                <p class="field-hint fm-url" data-file-url hidden></p>

                <p class="field-error" data-file-error hidden></p>

                <div class="fm-actions">
                    <button type="button" class="btn btn-ghost btn-sm" data-file-copy hidden>
                        <x-web.home.icon name="file" :size="14" />
                        Salin URL
                    </button>
                </div>

                {{--
                    Dua form dengan action berisi PENAMPUNG, ditukar JS saat
                    tombol di baris ditekan. Nama route tetap satu-satunya
                    sumber URL — tidak ada path yang ditulis ulang di
                    JavaScript. Teknik yang sama dipakai polling di halaman
                    Upload Queue.
                --}}
                <form method="POST" class="admin-form fm-form" data-file-form-rename hidden
                      action="{{ route('admin.files.rename', ['source' => 'episode_video', 'id' => 0]) }}">
                    @csrf
                    <label class="field">
                        <span class="field-required">Nama baru</span>
                        <input type="text" name="name" class="control" maxlength="120" required
                               data-file-input-name>
                        <span class="field-hint">
                            Ekstensinya dipertahankan otomatis. Mengganti ekstensi lewat
                            sini tidak diizinkan — <code>mime_type</code> yang tersimpan
                            tidak ikut berubah, dan daftar ekstensi terlarang di Storage
                            Engine tidak boleh bisa dilewati dari jalur ganti nama.
                        </span>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Ganti nama</button>
                    </div>
                </form>

                <form method="POST" class="admin-form fm-form" data-file-form-move hidden
                      action="{{ route('admin.files.move', ['source' => 'episode_video', 'id' => 0]) }}">
                    @csrf
                    <label class="field">
                        <span class="field-required">Direktori tujuan</span>
                        <input type="text" name="directory" class="control" maxlength="400" required
                               data-file-input-dir>
                        <span class="field-hint">
                            Di provider yang SAMA. Perpindahan antar provider belum ada —
                            itu operasi yang perlu mengalirkan isi berkas dan tahan
                            terhadap kegagalan di tengah jalan.
                        </span>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Pindahkan</button>
                    </div>
                </form>
            </div>
        </section>

    @endif

    <p class="queue-note">
        <x-web.home.icon name="database" :size="13" />
        Daftar ini dibaca dari <code>episode_videos</code> dan
        <code>drama_assets</code>, bukan dari isi bucket. Berkas yang barisnya
        sudah hilang dari database tidak akan muncul di sini meskipun objeknya
        masih ada di penyimpanan.
    </p>

@endsection
