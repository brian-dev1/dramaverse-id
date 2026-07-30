@extends('web.layouts.admin')

@section('title', $title)

@php
    // Modul baca-saja tidak mendaftarkan route tulis. Periksa keberadaannya
    // supaya tombol yang tidak berfungsi tidak pernah dirender.
    $canCreate  = Route::has('admin.'.$routeKey.'.create');
    $canEdit    = Route::has('admin.'.$routeKey.'.edit');
    $canDelete  = Route::has('admin.'.$routeKey.'.destroy');
    $canRestore = Route::has('admin.'.$routeKey.'.restore');
    $canBulk    = ! empty($bulkActions) && Route::has('admin.'.$routeKey.'.bulk');

    // Aksi status. Digerakkan Route::has(), bukan pemeriksaan $routeKey,
    // supaya modul lain yang nanti mendaftarkan route bernama sama langsung
    // mendapat tombolnya tanpa menyunting view ini.
    $canEnable  = Route::has('admin.'.$routeKey.'.enable');
    $canDisable = Route::has('admin.'.$routeKey.'.disable');
    $canDefault = Route::has('admin.'.$routeKey.'.default');
    $canTest    = Route::has('admin.'.$routeKey.'.test');

    // Editor prioritas hanya masuk akal bila route-nya ada DAN kolomnya
    // memang ditampilkan.
    $canPriority = Route::has('admin.'.$routeKey.'.priority')
        && in_array('priority', $columns, true);

    $hasAction  = $canEdit || $canDelete || $canEnable || $canDisable
        || $canDefault || $canTest;
    $colspan    = count($columns) + ($canBulk ? 1 : 0) + ($hasAction ? 1 : 0);
@endphp

@section('content')

    <form method="GET" class="admin-toolbar">
        <div class="toolbar-search">
            <x-web.home.icon name="search" :size="15" />
            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Cari {{ Str::lower($title) }}..." class="control">
        </div>

        @foreach ($filters as $field => $filter)
            <select name="{{ $field }}" class="control control-sm">
                <option value="">{{ $filter['label'] }}: semua</option>
                @foreach ($filter['options'] as $val => $text)
                    <option value="{{ $val }}" @selected((string) request($field) === (string) $val)>
                        {{ $text }}
                    </option>
                @endforeach
            </select>
        @endforeach

        @if ($softDeletes)
            <label class="checkbox-item">
                <input type="checkbox" name="trashed" value="1" @checked(request()->boolean('trashed'))>
                Terhapus
            </label>
        @endif

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if (request()->hasAny(array_merge(['q', 'trashed'], array_keys($filters))))
            <a href="{{ route('admin.'.$routeKey.'.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif

        <div class="toolbar-actions">
            @if ($routeKey === 'episode' && Route::has('admin.episode.batch'))
                <a href="{{ route('admin.episode.batch', request()->only('drama_id')) }}" class="btn btn-ghost btn-sm">
                    <x-web.home.icon name="list" :size="14" />
                    Tambah massal
                </a>
            @endif

            @if ($canCreate)
                <a href="{{ route('admin.'.$routeKey.'.create') }}" class="btn btn-primary btn-sm">
                    <x-web.home.icon name="plus" :size="14" />
                    Tambah
                </a>
            @endif
        </div>
    </form>

    @if ($records->isEmpty())

        <div class="empty-state">
            <h3>Belum ada {{ Str::lower($title) }}</h3>
            <p>
                @if ($keyword || request()->hasAny(array_keys($filters)))
                    Tidak ada data yang cocok dengan pencarian Anda.
                @elseif ($canCreate)
                    Tambahkan {{ Str::lower($title) }} pertama untuk mulai mengisi katalog.
                @else
                    Data akan muncul di sini seiring aktivitas pengguna.
                @endif
            </p>

            @if ($canCreate)
                <a href="{{ route('admin.'.$routeKey.'.create') }}" class="btn btn-primary">
                    Tambah {{ $title }}
                </a>
            @endif
        </div>

    @else

        @if ($canBulk)
            <form method="POST" action="{{ route('admin.'.$routeKey.'.bulk') }}" data-bulk-form>
            @csrf

            <div class="bulk-bar" data-bulk-bar hidden>
                <span data-bulk-count>0 dipilih</span>

                <select name="action" class="control control-sm" required>
                    <option value="">Pilih aksi</option>
                    @foreach ($bulkActions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-ghost btn-sm">Jalankan</button>
            </div>
        @endif

        @php
            // Pengurutan seret-lepas hanya masuk akal bila daftar berisi
            // satu drama dan belum diurutkan ulang oleh pengguna.
            $sortable_dnd = $routeKey === 'episode'
                && request()->filled('drama_id')
                && ! request()->filled('sort')
                && Route::has('admin.episode.reorder');
        @endphp

        @if ($sortable_dnd)
            <p class="dnd-hint">
                <x-web.home.icon name="sort" :size="13" />
                Seret baris untuk mengubah urutan episode. Perubahan tersimpan otomatis.
            </p>
        @endif

        @if ($canPriority)
            {{--
                Formulir prioritas berdiri DI LUAR tabel, dan input di dalam
                tabel menempel padanya lewat atribut form="...".

                Alasannya: form tidak boleh bersarang. Kalau formulir ini
                dibuat melingkupi tabel, ia akan membungkus form tombol Hapus
                dan Pulihkan yang ada di dalam baris — dan parser HTML
                membuang tag <form> yang bersarang, sehingga tombol-tombol itu
                justru mengirim formulir yang salah.
            --}}
            <form method="POST" action="{{ route('admin.'.$routeKey.'.priority') }}"
                  id="priority-form" class="bulk-bar">
                @csrf
                <span>Ubah angka prioritas di tabel. Angka lebih kecil dicoba lebih dulu.</span>
                <button type="submit" class="btn btn-ghost btn-sm">Simpan prioritas</button>
            </form>
        @endif

        <div class="table-wrap">
            <table class="data-table {{ $sortable_dnd ? 'is-sortable' : '' }}"
                   @if ($sortable_dnd)
                       data-reorder
                       data-reorder-url="{{ route('admin.episode.reorder') }}"
                       data-drama-id="{{ request('drama_id') }}"
                   @endif>
                <thead>
                    <tr>
                        @if ($canBulk)
                            <th class="col-check">
                                <input type="checkbox" data-bulk-all aria-label="Pilih semua">
                            </th>
                        @endif

                        @foreach ($columns as $label => $path)
                            <th>
                                @if (in_array($path, $sortable, true))
                                    @php
                                        $dir = request('sort') === $path && request('dir') === 'asc' ? 'desc' : 'asc';
                                    @endphp
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => $path, 'dir' => $dir]) }}"
                                       class="th-sort {{ request('sort') === $path ? 'active' : '' }}">
                                        {{ $label }}
                                        <x-web.home.icon name="sort" :size="11" />
                                    </a>
                                @else
                                    {{ $label }}
                                @endif
                            </th>
                        @endforeach

                        @if ($hasAction)
                            <th class="col-actions">Aksi</th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @foreach ($records as $record)
                        <tr @if ($sortable_dnd) draggable="true" data-id="{{ $record->id }}" @endif>
                            @if ($canBulk)
                                <td class="col-check">
                                    <input type="checkbox" name="ids[]" value="{{ $record->id }}"
                                           data-bulk-item aria-label="Pilih baris">
                                </td>
                            @endif

                            @php
                                $isTrashed = $softDeletes && $record->trashed();

                                // Keadaan baris, dibaca secara aman: modul yang
                                // tidak punya konsep aktif/default tetap
                                // dirender seperti sebelumnya.
                                $isActive = method_exists($record, 'isActive')
                                    ? $record->isActive()
                                    : null;

                                $isDefault = isset($record->is_default)
                                    ? (bool) $record->is_default
                                    : false;
                            @endphp

                            @foreach ($columns as $label => $path)
                                @if ($canPriority && $path === 'priority' && ! $isTrashed)
                                    <td>
                                        <input type="number" form="priority-form"
                                               name="priority[{{ $record->id }}]"
                                               value="{{ $record->priority }}"
                                               min="0" max="65535" step="1"
                                               class="control control-sm"
                                               aria-label="Priority {{ $record->name ?? $record->id }}">
                                    </td>
                                @else
                                    <td><x-admin.cell :record="$record" :path="$path" /></td>
                                @endif
                            @endforeach

                            @if ($hasAction)
                                <td class="col-actions">
                                    @if ($isTrashed && $canRestore)
                                        <form method="POST"
                                              action="{{ route('admin.'.$routeKey.'.restore', $record->id) }}"
                                              class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="Pulihkan" aria-label="Pulihkan">
                                                <x-web.home.icon name="restore" :size="15" />
                                            </button>
                                        </form>
                                    @else
                                        {{--
                                            Test Connection dirender untuk
                                            setiap baris hidup, termasuk yang
                                            nonaktif dan yang belum lengkap.
                                            Justru di situ gunanya: mengujinya
                                            adalah cara mengetahui apa yang
                                            masih kurang, dan hasil gagal
                                            menyebutkan alasannya.
                                        --}}
                                        @if ($canTest)
                                            <form method="POST"
                                                  action="{{ route('admin.'.$routeKey.'.test', $record->id) }}"
                                                  class="inline-form">
                                                @csrf
                                                <button type="submit" class="btn-icon"
                                                        title="Test Connection" aria-label="Test Connection">
                                                    <x-web.home.icon name="activity" :size="15" />
                                                </button>
                                            </form>
                                        @endif

                                        {{--
                                            Set Default hanya untuk baris yang
                                            aktif dan belum menjadi default.
                                            Provider nonaktif memang ditolak
                                            service-nya — merender tombol yang
                                            pasti gagal cuma memindahkan
                                            kekecewaan ke satu klik kemudian.
                                        --}}
                                        @if ($canDefault && $isActive === true && ! $isDefault)
                                            <form method="POST"
                                                  action="{{ route('admin.'.$routeKey.'.default', $record->id) }}"
                                                  class="inline-form">
                                                @csrf
                                                <button type="submit" class="btn-icon"
                                                        title="Jadikan default" aria-label="Jadikan default">
                                                    <x-web.home.icon name="star" :size="15" />
                                                </button>
                                            </form>
                                        @endif

                                        {{--
                                            Disable tidak dirender untuk baris
                                            default. Provider default wajib
                                            aktif, jadi tombolnya akan selalu
                                            ditolak. Jalan keluarnya memang
                                            memindahkan status default ke
                                            provider lain lebih dulu.
                                        --}}
                                        @if ($isActive === true && $canDisable && ! $isDefault)
                                            <form method="POST"
                                                  action="{{ route('admin.'.$routeKey.'.disable', $record->id) }}"
                                                  class="inline-form">
                                                @csrf
                                                <button type="submit" class="btn-icon"
                                                        title="Nonaktifkan" aria-label="Nonaktifkan">
                                                    <x-web.home.icon name="close" :size="15" />
                                                </button>
                                            </form>
                                        @elseif ($isActive === false && $canEnable)
                                            <form method="POST"
                                                  action="{{ route('admin.'.$routeKey.'.enable', $record->id) }}"
                                                  class="inline-form">
                                                @csrf
                                                <button type="submit" class="btn-icon"
                                                        title="Aktifkan" aria-label="Aktifkan">
                                                    <x-web.home.icon name="check" :size="15" />
                                                </button>
                                            </form>
                                        @endif

                                        @if ($canEdit)
                                            <a href="{{ route('admin.'.$routeKey.'.edit', $record->id) }}"
                                               class="btn-icon" title="Ubah" aria-label="Ubah">
                                                <x-web.home.icon name="edit" :size="15" />
                                            </a>
                                        @endif

                                        @if ($canDelete)
                                            <x-admin.confirm
                                                :action="route('admin.'.$routeKey.'.destroy', $record->id)"
                                                message="Data ini akan dihapus. Tindakan ini tidak dapat dibatalkan." />
                                        @endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($canBulk)
            </form>
        @endif

        <div class="pagination-wrap">{{ $records->links() }}</div>

    @endif

@endsection
