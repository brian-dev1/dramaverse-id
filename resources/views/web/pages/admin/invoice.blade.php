@extends('web.layouts.admin')

@section('title', 'Tagihan')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Total tagihan" :value="$stats['total']"   icon="file" />
        <x-admin.stat-card label="Menunggu"      :value="$stats['pending']" icon="clock" />
        <x-admin.stat-card label="Lunas"         :value="$stats['paid']"    icon="check" />
        <x-admin.stat-card label="Langganan aktif" :value="$stats['active']" icon="users" />
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Pendapatan</h2>
            <span class="panel-meta">Dihitung dari tagihan berstatus Lunas</span>
        </div>

        <div class="detail-body-admin">
            <dl class="settings-meta">
                <dt>Sepanjang waktu</dt>
                <dd>Rp {{ number_format($stats['revenue'], 0, ',', '.') }}</dd>

                <dt>30 hari terakhir</dt>
                <dd>Rp {{ number_format($stats['revenue_30d'], 0, ',', '.') }}</dd>
            </dl>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Daftar tagihan</h2>
            <span class="panel-meta">Cari lewat nomor, nama pengguna, paket, atau referensi transaksi</span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.invoice.index') }}" class="toolbar-search">
                <input type="search" name="q" value="{{ $q }}" class="control control-sm"
                       placeholder="Nomor, pengguna, paket, referensi">

                <select name="status" class="control control-sm">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($status === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="sort" class="control control-sm">
                    <option value="id"      @selected($sort === 'id')>Terbaru</option>
                    <option value="number"  @selected($sort === 'number')>Nomor</option>
                    <option value="total"   @selected($sort === 'total')>Nominal</option>
                    <option value="status"  @selected($sort === 'status')>Status</option>
                    <option value="paid_at" @selected($sort === 'paid_at')>Waktu bayar</option>
                    <option value="due_at"  @selected($sort === 'due_at')>Jatuh tempo</option>
                </select>

                <select name="dir" class="control control-sm">
                    <option value="desc" @selected($dir === 'desc')>Turun</option>
                    <option value="asc"  @selected($dir === 'asc')>Naik</option>
                </select>

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" /> Terapkan
                </button>
            </form>

            <div class="toolbar-actions">
                <a href="{{ route('admin.invoice.export', request()->query()) }}" class="btn btn-sm">
                    <x-web.home.icon name="file" :size="14" /> Ekspor CSV
                </a>
            </div>
        </div>

        @if ($invoices->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Tidak ada tagihan yang cocok.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            <th>Pengguna</th>
                            <th>Paket</th>
                            <th>Total</th>
                            <th>Metode</th>
                            <th>Status</th>
                            <th>Dibuat</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td><span class="fm-key">{{ $invoice->number }}</span></td>
                                <td>
                                    {{ $invoice->user?->name ?? '—' }}
                                    @if ($invoice->user?->telegram_username)
                                        <br><span class="cell-empty">{{ '@'.$invoice->user->telegram_username }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $invoice->plan_name }}
                                    <br><span class="cell-empty">{{ $invoice->plan_duration }} hari</span>
                                </td>
                                <td>Rp {{ number_format((float) $invoice->total, 0, ',', '.') }}</td>
                                <td>
                                    {{ $invoice->latestTransaction?->provider?->name ?? '—' }}
                                    @if ($invoice->latestTransaction?->provider?->isSandbox())
                                        <br><span class="badge badge-pending">sandbox</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $invoice->status->badge() }}">
                                        {{ $invoice->status->label() }}
                                    </span>
                                    @if ($invoice->isOverdue())
                                        <br><span class="cell-empty">lewat jatuh tempo</span>
                                    @endif
                                </td>
                                <td>{{ $invoice->created_at?->ringkas() ?? '—' }}</td>
                                <td class="col-actions">
                                    <a href="{{ route('admin.invoice.show', $invoice->number) }}" class="btn btn-sm">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $invoices->links() }}
        @endif
    </section>

@endsection
