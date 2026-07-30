@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    {{--
        Tidak ada aksi massal di halaman ini, jadi tidak ada form yang
        membungkus tabel. Setiap tombol berdiri di form-nya sendiri — bug form
        bersarang yang tercatat di STATUS.md tidak berlaku di sini.
    --}}
    <div class="admin-toolbar" data-monitor
         data-refresh-url="{{ route('admin.storage-monitor.refresh') }}">

        <p class="panel-meta">
            Angka dibaca dari database aplikasi, bukan dari isi bucket.
            Diperbarui <span data-monitor-at>{{ $at }}</span>.
        </p>

        <div class="toolbar-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-monitor-refresh>
                <x-web.home.icon name="restore" :size="14" />
                Refresh Status
            </button>

            <a href="{{ route('admin.storage.index') }}" class="btn btn-primary btn-sm">
                <x-web.home.icon name="database" :size="14" />
                Storage Manager
            </a>
        </div>
    </div>

    {{--
        Kartu angka ditulis sebagai markup biasa, BUKAN lewat
        <x-admin.stat-card>. Dua sebabnya:

        1. Komponen itu menjalankan number_format() pada nilainya, yang akan
           mengubah "1.24 GB" menjadi "1".
        2. Komponen itu tidak meneruskan atribut tambahan, sehingga tidak ada
           tempat memasang penanda data-* yang dibutuhkan tombol Refresh
           Status untuk menemukan angka mana yang harus diganti.

        Kelas CSS-nya sama persis dengan yang dipakai komponen, jadi
        tampilannya tidak berbeda dari kartu di halaman lain.
    --}}
    <div class="stat-row">
        <a href="{{ route('admin.storage.index') }}" class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="database" :size="16" /></span>
            <span class="stat-value" data-monitor-value="providers.total">{{ number_format($providers['total']) }}</span>
            <span class="stat-label">Total provider</span>
        </a>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="check" :size="16" /></span>
            <span class="stat-value" data-monitor-value="providers.active">{{ number_format($providers['active']) }}</span>
            <span class="stat-label">Aktif</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="close" :size="16" /></span>
            <span class="stat-value" data-monitor-value="providers.inactive">{{ number_format($providers['inactive']) }}</span>
            <span class="stat-label">Nonaktif</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="activity" :size="16" /></span>
            <span class="stat-value" data-monitor-value="test.ok">{{ number_format($test['ok']) }}</span>
            <span class="stat-label">Terhubung</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="shield" :size="16" /></span>
            <span class="stat-value" data-monitor-value="test.failed">{{ number_format($test['failed']) }}</span>
            <span class="stat-label">Gagal terhubung</span>
        </div>
    </div>

    <div class="stat-row">
        <a href="{{ route('admin.files.index') }}" class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="file" :size="16" /></span>
            <span class="stat-value" data-monitor-value="files.total">{{ number_format($files['total']) }}</span>
            <span class="stat-label">Total berkas terunggah</span>
        </a>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="database" :size="16" /></span>
            <span class="stat-value" data-monitor-value="files.size_human">{{ $files['size_human'] }}</span>
            <span class="stat-label">Total ruang terpakai</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="clock" :size="16" /></span>
            <span class="stat-value" data-monitor-value="files.today">{{ number_format($files['today']) }}</span>
            <span class="stat-label">Unggahan hari ini</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="chart" :size="16" /></span>
            <span class="stat-value" data-monitor-value="files.month">{{ number_format($files['month']) }}</span>
            <span class="stat-label">Unggahan bulan ini</span>
        </div>

        <div class="stat-card">
            <span class="stat-icon"><x-web.home.icon name="restore" :size="16" /></span>
            <span class="stat-value" data-monitor-value="test.never">{{ number_format($test['never']) }}</span>
            <span class="stat-label">Belum pernah diuji</span>
        </div>
    </div>

    {{-- Provider default --}}
    <section class="panel" role="{{ $default['ok'] ? 'status' : 'alert' }}">
        <div class="panel-head">
            <h2>Provider default</h2>
            <span class="badge {{ $default['ok'] ? 'badge-on' : 'badge-off' }}">
                {{ $default['ok'] ? 'Siap' : 'Perlu diperbaiki' }}
            </span>
        </div>

        <div class="detail-body-admin">
            <p class="panel-meta">
                {{ $default['name'] ?: 'Belum ditetapkan' }} — dipakai setiap unggahan
                yang memilih mode Auto.
            </p>

            @if ($default['problem'])
                <p class="field-error">{{ $default['problem'] }}</p>
            @else
                <p class="field-hint">
                    Mode Auto akan mengirim berkas ke provider ini.
                </p>
            @endif
        </div>
    </section>

    {{-- Peringatan provider aktif yang belum siap --}}
    @if ($providers['unusable'] > 0)
        <p class="queue-note monitor-alert" role="alert">
            <x-web.home.icon name="shield" :size="13" />
            {{ $providers['unusable'] }} provider berstatus aktif tetapi belum bisa
            dipakai. Sebabnya disebut di kolom Keadaan pada tabel di bawah —
            unggahan ke provider itu akan gagal meski badge-nya hijau.
        </p>
    @endif

    {{-- Tabel per provider --}}
    @if (empty($rows))

        <div class="empty-state">
            <h3>Belum ada storage provider</h3>
            <p>Tambahkan provider lebih dulu supaya ada yang bisa dipantau.</p>
            <a href="{{ route('admin.storage.create') }}" class="btn btn-primary">
                Tambah provider
            </a>
        </div>

    @else

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Driver</th>
                        <th>Status</th>
                        <th>Koneksi</th>
                        <th>Uji terakhir</th>
                        <th>Berkas</th>
                        <th>Ruang</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($rows as $row)
                        <tr data-monitor-row="{{ $row['id'] }}">
                            <td>
                                {{ $row['name'] }}
                                @if ($row['is_default'])
                                    <span class="asset-sub">provider default</span>
                                @endif

                                @if ($row['not_ready'])
                                    <span class="asset-sub monitor-problem">{{ $row['not_ready'] }}</span>
                                @endif
                            </td>

                            <td>{{ $row['driver'] }}</td>

                            <td>
                                <span class="badge badge-status {{ $row['active'] ? 'badge-on' : 'badge-off' }}">
                                    {{ $row['active'] ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>

                            <td>
                                <span class="badge badge-status {{ $row['test_badge'] }}" data-monitor-test>
                                    {{ $row['test_label'] }}
                                </span>
                            </td>

                            <td data-monitor-tested>
                                {{ $row['tested_at'] ?: '—' }}
                                @if ($row['duration'])
                                    <span class="asset-sub">{{ $row['duration'] }}</span>
                                @endif
                            </td>

                            <td data-monitor-files>{{ number_format($row['files']) }}</td>

                            <td data-monitor-size>{{ $row['size_human'] }}</td>

                            <td class="col-actions">
                                <form method="POST"
                                      action="{{ route('admin.storage-monitor.test', ['id' => $row['id']]) }}"
                                      class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn-icon"
                                            title="Test Connection" aria-label="Test Connection">
                                        <x-web.home.icon name="activity" :size="15" />
                                    </button>
                                </form>

                                <a href="{{ route('admin.files.index', ['provider' => $row['id']]) }}"
                                   class="btn-icon" title="Lihat berkasnya" aria-label="Lihat berkasnya">
                                    <x-web.home.icon name="file" :size="15" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    @endif

    <p class="queue-note">
        <x-web.home.icon name="file" :size="13" />
        Jumlah dan ukuran berkas dihitung dari <code>episode_videos</code> dan
        <code>drama_assets</code>. Objek yatim — berkas yang masih ada di bucket
        tetapi barisnya sudah hilang — <strong>tidak</strong> terhitung di sini.
        Objek yatim dicatat di log aplikasi dengan peristiwa berakhiran
        <code>.orphan</code>.
    </p>

@endsection
