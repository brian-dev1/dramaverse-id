@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <div class="admin-alert is-warning">
        Daftar ini membandingkan jumlah part drama dengan part yang tersedia dan sumber videonya.
        URL video yang ditempel langsung tetap dihitung sebagai sudah terisi.
    </div>

    <form method="GET" class="admin-toolbar">
        <div class="toolbar-search">
            <x-web.home.icon name="search" :size="15" />
            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Cari drama..." class="control">
        </div>

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if ($keyword !== '')
            <a href="{{ route('admin.drama.incomplete') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif

        <div class="toolbar-actions">
            <a href="{{ route('admin.drama.index') }}" class="btn btn-ghost btn-sm">
                Kembali ke Drama
            </a>
        </div>
    </form>

    @if ($dramas->isEmpty())
        <div class="empty-state">
            <h3>{{ $keyword !== '' ? 'Tidak ada hasil' : 'Semua drama sudah lengkap' }}</h3>
            <p>
                {{ $keyword !== ''
                    ? 'Tidak ada drama belum lengkap yang cocok dengan pencarian Anda.'
                    : 'Tidak ditemukan nomor part yang hilang atau part tanpa sumber video.' }}
            </p>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Poster</th>
                        <th>Judul</th>
                        <th>Target</th>
                        <th>Part belum dibuat</th>
                        <th>Video belum ada</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dramas as $drama)
                        @php
                            $gaps = $drama->upload_gaps;
                        @endphp
                        <tr>
                            <td><x-admin.cell :record="$drama" path="poster" /></td>
                            <td>
                                <a href="{{ route('admin.drama.edit', $drama->id) }}">
                                    {{ $drama->title }}
                                </a>
                                @if ($drama->country)
                                    <br><span class="cell-empty">{{ $drama->country->name }}</span>
                                @endif
                            </td>
                            <td>{{ $gaps['expected'] }} part</td>
                            <td>
                                @if ($gaps['missing_episodes_label'] !== '')
                                    <span class="badge badge-off">Part {{ $gaps['missing_episodes_label'] }}</span>
                                @else
                                    <span class="cell-empty">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($gaps['missing_videos_label'] !== '')
                                    <span class="badge badge-off">Part {{ $gaps['missing_videos_label'] }}</span>
                                @else
                                    <span class="cell-empty">—</span>
                                @endif
                            </td>
                            <td class="col-actions">
                                @if ($gaps['missing_episodes'] !== [])
                                    <a href="{{ route('admin.episode.batch', ['drama_id' => $drama->id]) }}"
                                       class="btn-icon" title="Tambah part yang hilang" aria-label="Tambah part yang hilang">
                                        <x-web.home.icon name="plus" :size="15" />
                                    </a>
                                @endif

                                @if ($gaps['missing_videos'] !== [])
                                    <a href="{{ route('admin.episode.video.form', ['drama_id' => $drama->id]) }}"
                                       class="btn-icon" title="Unggah video" aria-label="Unggah video">
                                        <x-web.home.icon name="play" :size="15" />
                                    </a>
                                @endif

                                <a href="{{ route('admin.episode.index', ['drama_id' => $drama->id]) }}"
                                   class="btn-icon" title="Kelola part" aria-label="Kelola part">
                                    <x-web.home.icon name="list" :size="15" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $dramas->links() }}
    @endif

@endsection
