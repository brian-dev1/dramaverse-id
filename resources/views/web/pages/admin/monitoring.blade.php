@extends('web.layouts.admin')

@section('title', 'Monitoring')

@php
    // Pemetaan status ke kelas badge yang sudah ada di CSS panel.
    $badge = fn (?string $s) => match ($s) {
        'ok'   => 'badge-on',
        'warn' => 'badge-pending',
        'down' => 'badge-off',
        default => 'badge',
    };

    $ukuran = function (?int $b): string {
        if ($b === null) return '—';
        if ($b >= 1073741824) return number_format($b / 1073741824, 2).' GB';
        if ($b >= 1048576)    return number_format($b / 1048576, 1).' MB';
        return number_format($b / 1024, 0).' KB';
    };
@endphp

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>Kesehatan sistem</h2>
            <span class="panel-meta">
                Digabung dari Storage Monitoring dan Telegram Health yang sudah ada,
                ditambah basis data, cache, antrean, scheduler, cadangan, dan server
            </span>
        </div>

        <div class="detail-body-admin {{ $health['healthy'] ? '' : 'monitor-alert' }}">
            <p class="page-subtitle">
                @if ($health['healthy'])
                    Tidak ada bagian yang berstatus mati.
                @else
                    <strong>Ada bagian yang mati.</strong> Lihat baris berbadge merah di bawah.
                @endif
            </p>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Bagian</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ([
                        'database'  => 'Basis data',
                        'cache'     => 'Cache',
                        'queue'     => 'Antrean',
                        'scheduler' => 'Scheduler',
                        'backup'    => 'Cadangan',
                        'server'    => 'Server',
                        'telegram'  => 'Telegram',
                        'storage'   => 'Storage',
                        'errors'    => 'Galat',
                    ] as $kunci => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td>
                                <span class="badge {{ $badge($health[$kunci]['status'] ?? null) }}">
                                    {{ $health[$kunci]['status'] ?? '?' }}
                                </span>
                            </td>
                            <td>
                                {{ $health[$kunci]['pesan'] ?? '—' }}

                                @if ($kunci === 'queue' && ! empty($health['queue']['queues']))
                                    <br>
                                    <span class="cell-empty">
                                        @foreach ($health['queue']['queues'] as $nama => $jumlah)
                                            {{ $nama }}: {{ $jumlah }}@if (! $loop->last), @endif
                                        @endforeach
                                    </span>
                                @endif

                                @if ($kunci === 'server' && isset($health['server']['disk_free']))
                                    <br>
                                    <span class="cell-empty">
                                        Sisa {{ $ukuran($health['server']['disk_free']) }}
                                        dari {{ $ukuran($health['server']['disk_total']) }} —
                                        PHP {{ $health['server']['php'] }}
                                    </span>
                                @endif

                                @if ($kunci === 'errors')
                                    <br>
                                    <span class="cell-empty">
                                        error {{ $health['errors']['counts']['error'] }},
                                        warning {{ $health['errors']['counts']['warning'] }},
                                        info {{ $health['errors']['counts']['info'] }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Cadangan</h2>
            <span class="panel-meta">
                Basis data + <code>.env</code>. Video TIDAK ikut — yang dicadangkan petanya,
                bukan berkasnya
            </span>
        </div>

        <div class="admin-toolbar">
            <div class="toolbar-actions">
                <form method="POST" action="{{ route('admin.monitoring.backup') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <x-web.home.icon name="database" :size="14" />
                        Cadangkan sekarang
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.monitoring.prune') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-sm">
                        <x-web.home.icon name="trash" :size="14" />
                        Pangkas yang lama
                    </button>
                </form>
            </div>
        </div>

        @if ($backups->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Belum ada cadangan. Tekan "Cadangkan sekarang", atau tunggu jadwal
                    harian pukul 02:30 — yang hanya berjalan bila cron sudah dipasang.
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Berkas</th>
                            <th>Ukuran</th>
                            <th>Dibuat</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $b)
                            <tr>
                                <td><span class="fm-key">{{ $b['nama'] }}</span></td>
                                <td>{{ $ukuran($b['size']) }}</td>
                                <td>
                                    {{ \App\Support\Waktu::ringkas($b['waktu']) }}
                                    <br><span class="cell-empty">{{ $b['waktu']->diffForHumans() }}</span>
                                </td>
                                <td class="col-actions">
                                    <form method="POST" action="{{ route('admin.monitoring.verify') }}"
                                          class="inline-form">
                                        @csrf
                                        <input type="hidden" name="nama" value="{{ $b['nama'] }}">
                                        <button type="submit" class="btn btn-sm">
                                            <x-web.home.icon name="check" :size="14" />
                                            Verifikasi
                                        </button>
                                    </form>

                                    <a href="{{ route('admin.monitoring.download', ['nama' => $b['nama']]) }}"
                                       class="btn btn-sm">
                                        <x-web.home.icon name="file" :size="14" />
                                        Unduh
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="detail-body-admin monitor-problem">
            <p class="page-subtitle">
                <strong>Berkas cadangan memuat <code>.env</code> dalam bentuk teks polos</strong> —
                termasuk APP_KEY, kredensial basis data, dan token bot. Perlakukan salinannya
                seperti kata sandi. Setiap unduhan tercatat di Log Aktivitas.
            </p>

            <p class="page-subtitle">
                Cadangan yang hanya ada di server yang sama dengan aplikasinya bukan cadangan
                yang sesungguhnya: ia melindungi dari tabel yang terhapus, bukan dari server
                yang hilang. Salin keluar server secara berkala.
            </p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Berkas log</h2></div>

        <div class="detail-body-admin">
            <dl class="settings-meta">
                <dt>Ukuran</dt>
                <dd>{{ $ukuran($logSize) }}</dd>

                <dt>Baca</dt>
                <dd>
                    <a href="{{ route('admin.system-log.index') }}">Log Sistem</a> —
                    <a href="{{ route('admin.telegram-log.index') }}">Log Telegram</a> —
                    <a href="{{ route('admin.logs.index') }}">Log Aktivitas</a>
                </dd>
            </dl>
        </div>
    </section>

@endsection
