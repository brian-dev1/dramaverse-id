@extends('web.layouts.admin')

@section('title', 'Laporan')

@php $chartsOnPage = true; @endphp

@section('content')

    {{-- Pemilih jenis laporan dan rentang tanggal --}}
    <form method="GET" class="admin-toolbar">
        <select name="type" class="control control-sm">
            @foreach ($types as $key => $label)
                <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <label class="range-label">
            Dari
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="control control-sm">
        </label>

        <label class="range-label">
            Sampai
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="control control-sm">
        </label>

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if ($total > 0)
            <div class="export-group">
                <a href="{{ route('admin.report.export', ['format' => 'xlsx'] + request()->only('type', 'from', 'to')) }}"
                   class="btn btn-primary btn-sm">
                    <x-web.home.icon name="file" :size="14" />
                    Excel
                </a>

                <a href="{{ route('admin.report.export', ['format' => 'csv'] + request()->only('type', 'from', 'to')) }}"
                   class="btn btn-ghost btn-sm">CSV</a>

                <a href="{{ route('admin.report.print', request()->only('type', 'from', 'to')) }}"
                   class="btn btn-ghost btn-sm" target="_blank" rel="noopener">PDF</a>
            </div>
        @endif
    </form>

    <div class="chart-grid">
        <x-admin.chart id="rep-revenue" title="Pendapatan 12 bulan terakhir" color="#EAC98C" money
                       :labels="$revenue['labels']" :values="$revenue['values']" />
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>{{ $types[$type] }}</h2>
            <span class="panel-meta">
                <span class="meta-item">{{ number_format($total) }} baris</span>
                @if ($total > 100)
                    <span class="meta-item">Menampilkan 100 pertama, unduh CSV untuk semuanya</span>
                @endif
            </span>
        </div>

        @if ($rows->isEmpty())
            <div class="empty-state">
                <h3>Tidak ada data</h3>
                <p>Tidak ada catatan pada rentang {{ \App\Support\Waktu::tanggal($from) }}
                   sampai {{ \App\Support\Waktu::tanggal($to) }}.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            @foreach ($headers as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($row as $value)
                                    <td>
                                        @if ($value instanceof \Illuminate\Support\Carbon)
                                            {{ \App\Support\Waktu::ringkas($value) }}
                                        @elseif (is_bool($value))
                                            <span class="badge {{ $value ? 'badge-on' : 'badge-off' }}">
                                                {{ $value ? 'Ya' : 'Tidak' }}
                                            </span>
                                        @elseif ($value === null || $value === '')
                                            <span class="cell-empty">—</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

@endsection
