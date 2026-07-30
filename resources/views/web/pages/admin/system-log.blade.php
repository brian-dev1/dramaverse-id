@extends('web.layouts.admin')

@section('title', 'Log Sistem')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Error"   :value="$counts['error']"   icon="close" />
        <x-admin.stat-card label="Warning" :value="$counts['warning']" icon="shield" />
        <x-admin.stat-card label="Info"    :value="$counts['info']"    icon="bell" />
        <x-admin.stat-card label="Lainnya" :value="$counts['lainnya']" icon="file" />
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Log Sistem</h2>
            <span class="panel-meta">
                Dibaca dari 2 MB terakhir berkas log, terbaru lebih dulu
            </span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.system-log.index') }}" class="toolbar-search">
                <input type="search" name="q" value="{{ $q }}" class="control control-sm"
                       placeholder="Cari di dalam pesan">

                <select name="channel" class="control control-sm">
                    @foreach ($channels as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($channel === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="level" class="control control-sm">
                    <option value="">Semua level</option>
                    @foreach ($levels as $l)
                        <option value="{{ $l }}" @selected($level === $l)>{{ ucfirst($l) }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" />
                    Terapkan
                </button>
            </form>
        </div>

        @if (! $ada)
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Berkas log belum ada. Ia dibuat sendiri begitu ada peristiwa pertama
                    yang tercatat.
                </p>
            </div>
        @elseif (empty($entries))
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Tidak ada baris yang cocok. Peristiwa yang lebih lama dari 2 MB terakhir
                    berkas tidak ikut terbaca di sini.
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Level</th>
                            <th>Peristiwa</th>
                            <th>Pesan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $e)
                            <tr>
                                <td>{{ $e['waktu'] }}</td>
                                <td>
                                    <span class="badge {{ in_array($e['level'], ['error', 'critical', 'alert', 'emergency'], true) ? 'badge-off' : ($e['level'] === 'warning' ? 'badge-pending' : 'badge-on') }}">
                                        {{ $e['level'] }}
                                    </span>
                                </td>
                                <td><span class="fm-key">{{ $e['event'] }}</span></td>
                                <td><span class="queue-log-item">{{ $e['pesan'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Catatan</h2></div>

        <div class="detail-body-admin">
            <p class="page-subtitle">
                Halaman ini membaca <strong>berkas log</strong> — apa yang rusak.
                <a href="{{ route('admin.logs.index') }}">Log Aktivitas</a> membaca tabel
                <code>activity_logs</code> — siapa melakukan apa. Keduanya saling melengkapi,
                bukan menggantikan.
            </p>

            <p class="page-subtitle">
                Token bot tidak pernah muncul di sini — ia diredaksi lebih dulu oleh
                TelegramClient. Kata sandi tidak pernah dicatat dalam keadaan apa pun.
            </p>

            <dl class="settings-meta">
                <dt>Berkas</dt>
                <dd><code>{{ $file }}</code></dd>

                <dt>Ukuran</dt>
                <dd>{{ number_format($size / 1048576, 2) }} MB</dd>
            </dl>
        </div>
    </section>

@endsection
