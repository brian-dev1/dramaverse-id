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
    $hasAction  = $canEdit || $canDelete;
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

        @if ($canCreate)
            <a href="{{ route('admin.'.$routeKey.'.create') }}" class="btn btn-primary btn-sm toolbar-add">
                <x-web.home.icon name="plus" :size="14" />
                Tambah
            </a>
        @endif
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

        <div class="table-wrap">
            <table class="data-table">
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
                        <tr>
                            @if ($canBulk)
                                <td class="col-check">
                                    <input type="checkbox" name="ids[]" value="{{ $record->id }}"
                                           data-bulk-item aria-label="Pilih baris">
                                </td>
                            @endif

                            @foreach ($columns as $label => $path)
                                <td><x-admin.cell :record="$record" :path="$path" /></td>
                            @endforeach

                            @if ($hasAction)
                                <td class="col-actions">
                                    @if ($softDeletes && $canRestore && $record->trashed())
                                        <form method="POST"
                                              action="{{ route('admin.'.$routeKey.'.restore', $record->id) }}"
                                              class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="Pulihkan" aria-label="Pulihkan">
                                                <x-web.home.icon name="restore" :size="15" />
                                            </button>
                                        </form>
                                    @else
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
