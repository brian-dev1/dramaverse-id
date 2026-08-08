@extends('web.layouts.admin')

@section('title', 'Episode')

@php
    /*
    | Tabel ini sengaja meniru daftar Drama: poster, judul, negara, tahun,
    | status. Menu Episode dan menu Drama menampilkan objek yang sama, jadi
    | menampilkannya dengan bentuk berbeda hanya membuat admin harus belajar
    | dua tata letak untuk satu daftar yang sama.
    |
    | Yang berbeda cuma kolom Episode — di sini ia dipecah gratis/VIP, karena
    | justru itu keputusan yang sedang diambil admin saat membuka menu ini.
    */
    $kolom = [
        'Poster' => 'poster',
        'Judul'  => 'title',
        'Negara' => 'country.name',
        'Tahun'  => 'release_year',
    ];
@endphp

@section('content')

    <form method="GET" class="admin-toolbar">
        <div class="toolbar-search">
            <x-web.home.icon name="search" :size="15" />
            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Cari drama..." class="control">
        </div>

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if ($keyword !== '')
            <a href="{{ route('admin.episode.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif

        <div class="toolbar-actions">
            <a href="{{ route('admin.episode.batch') }}" class="btn btn-primary btn-sm">
                <x-web.home.icon name="plus" :size="14" />
                Tambah episode
            </a>
        </div>
    </form>

    @if ($dramas->isEmpty())

        <div class="empty-state">
            <h3>Belum ada drama</h3>
            <p>
                @if ($keyword !== '')
                    Tidak ada drama yang cocok dengan pencarian Anda.
                @else
                    Episode dikelola per drama. Tambahkan drama dulu, lalu isi episodenya di sini.
                @endif
            </p>

            @if ($keyword === '' && Route::has('admin.drama.create'))
                <a href="{{ route('admin.drama.create') }}" class="btn btn-primary">Tambah Drama</a>
            @endif
        </div>

    @else

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($kolom as $label => $path)
                            <th>{{ $label }}</th>
                        @endforeach
                        <th>Episode</th>
                        <th>Status</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($dramas as $drama)
                        @php
                            $jumlah   = (int) $drama->episodes_count;
                            $vip      = (int) $drama->episodes_vip_count;
                            $gratis   = $jumlah - $vip;
                            $terbit   = (int) $drama->episodes_published_count;
                            $draf     = $jumlah - $terbit;
                            $kelolaUrl = route('admin.episode.index', ['drama_id' => $drama->id]);
                        @endphp

                        <tr>
                            @foreach ($kolom as $label => $path)
                                <td>
                                    @if ($path === 'title')
                                        <a href="{{ $kelolaUrl }}">{{ $drama->title }}</a>
                                    @else
                                        <x-admin.cell :record="$drama" :path="$path" />
                                    @endif
                                </td>
                            @endforeach

                            <td>
                                @if ($jumlah === 0)
                                    <span class="cell-empty">belum ada</span>
                                @else
                                    <a href="{{ $kelolaUrl }}">{{ $jumlah }} episode</a>
                                    <br>
                                    <span class="badge badge-off">{{ $gratis }} gratis</span>
                                    <span class="badge badge-on">{{ $vip }} VIP</span>
                                @endif
                            </td>

                            <td>
                                @if ($jumlah === 0)
                                    <span class="cell-empty">—</span>
                                @else
                                    <span class="badge badge-status">{{ $terbit }} terbit</span>
                                    @if ($draf > 0)
                                        <span class="badge badge-off">{{ $draf }} draf</span>
                                    @endif
                                @endif
                            </td>

                            <td class="col-actions">
                                <a href="{{ route('admin.episode.batch', ['drama_id' => $drama->id]) }}"
                                   class="btn-icon" title="Tambah episode" aria-label="Tambah episode">
                                    <x-web.home.icon name="plus" :size="15" />
                                </a>

                                <a href="{{ $kelolaUrl }}"
                                   class="btn-icon" title="Kelola episode" aria-label="Kelola episode">
                                    <x-web.home.icon name="list" :size="15" />
                                </a>

                                @if (Route::has('admin.episode.video.form'))
                                    <a href="{{ route('admin.episode.video.form', ['drama_id' => $drama->id]) }}"
                                       class="btn-icon" title="Unggah video" aria-label="Unggah video">
                                        <x-web.home.icon name="play" :size="15" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $dramas->links() }}

    @endif

@endsection
