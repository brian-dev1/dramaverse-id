@extends('web.layouts.admin')

@section('title', 'Log Pembayaran')

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>Log Pembayaran</h2>
            <span class="panel-meta">
                Baris berawalan <code>payment.</code> dari log Laravel, terbaru lebih dulu
            </span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.payment-log.index') }}" class="toolbar-search">
                <input type="search" name="q" value="{{ $q }}" class="control control-sm"
                       placeholder="Cari di dalam pesan">

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
                    Tidak ada baris yang cocok. Log dibaca dari 2 MB terakhir berkasnya —
                    peristiwa yang lebih lama dari itu tidak ikut terbaca di sini.
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
                                    <span class="badge {{ $e['level'] === 'error' ? 'badge-off' : ($e['level'] === 'warning' ? 'badge-pending' : 'badge-on') }}">
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
                Kredensial gateway tidak pernah muncul di sini — yang dicatat hanya
                payload transaksi, tidak pernah kunci API. Isi callback juga tidak
                ikut secara utuh: ia memuat nama dan email pembayar, jadi yang
                tersimpan hanya kunci-kuncinya beserta nomor tagihan, referensi,
                status, dan nominal.
            </p>

            <dl class="settings-meta">
                <dt>Berkas</dt>
                <dd><code>{{ $file }}</code></dd>
            </dl>
        </div>
    </section>

@endsection
