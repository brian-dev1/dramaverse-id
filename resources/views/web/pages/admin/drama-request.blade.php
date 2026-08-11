@extends('web.layouts.admin')

@section('title', 'Request Drama')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Menunggu ditinjau" :value="$jumlah['pending']" icon="clock" />
        <x-admin.stat-card label="Sedang diproses"   :value="$jumlah['process']" icon="activity" />
    </div>

    @if ($populer->isNotEmpty())
        <section class="panel">
            <div class="panel-head">
                <h2>Paling banyak diminta</h2>
                <span class="panel-meta">
                    Dihitung dari permintaan yang belum selesai, judul serupa digabung
                </span>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Judul</th><th>Jumlah peminta</th></tr></thead>
                    <tbody>
                        @foreach ($populer as $p)
                            <tr>
                                <td>{{ $p['judul'] }}</td>
                                <td><span class="badge badge-on">{{ $p['jumlah'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Daftar permintaan</h2>
            <span class="panel-meta">{{ $daftar->total() }} permintaan</span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.drama-request.index') }}" class="toolbar-search">
                <input type="search" name="q" value="{{ $q }}" class="control control-sm"
                       placeholder="Cari judul">

                <select name="status" class="control control-sm">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($status === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" /> Terapkan
                </button>
            </form>
        </div>

        @if ($daftar->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Tidak ada permintaan yang cocok.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Judul diminta</th>
                            <th>Peminta</th>
                            <th>Status &amp; tindakan</th>
                            <th>Dikirim</th>
                            <th class="col-actions">Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($daftar as $r)
                            <tr>
                                <td>
                                    {{ $r->title }}
                                    @if ($r->year)
                                        <br><span class="cell-empty">{{ $r->year }}</span>
                                    @endif
                                    @if ($r->note)
                                        <br><span class="cell-empty">{{ Str::limit($r->note, 90) }}</span>
                                    @endif
                                </td>

                                <td>
                                    {{ $r->user?->name ?? '—' }}
                                    @if ($r->user?->telegram_username)
                                        <br><span class="cell-empty">{{ '@'.$r->user->telegram_username }}</span>
                                    @endif
                                    @unless ($r->user?->telegram_id)
                                        {{-- Tanpa telegram_id, pemberitahuan hanya
                                             sampai sebagai notifikasi di situs. --}}
                                        <br><span class="queue-error">tanpa Telegram</span>
                                    @endunless
                                </td>

                                <td>
                                    <form method="POST" action="{{ route('admin.drama-request.update', $r->id) }}"
                                          class="admin-inline-form">
                                        @csrf
                                        @method('PUT')

                                        <select name="status" class="control control-sm">
                                            @foreach ($statuses as $nilai => $label)
                                                <option value="{{ $nilai }}" @selected($r->status->value === $nilai)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>

                                        {{-- Menghubungkan ke drama yang sudah dibuat.
                                             Inilah yang membuat tombol "Tonton" muncul
                                             di halaman pengguna dan di pesan Telegram. --}}
                                        <select name="drama_id" class="control control-sm">
                                            <option value="">— belum dihubungkan —</option>
                                            @foreach ($dramas as $d)
                                                <option value="{{ $d->id }}" @selected($r->drama_id === $d->id)>
                                                    {{ Str::limit($d->title, 40) }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <input type="text" name="admin_note" value="{{ $r->admin_note }}"
                                               class="control control-sm" maxlength="500"
                                               placeholder="Catatan untuk peminta (opsional)">

                                        <button type="submit" class="btn btn-sm">Simpan</button>
                                    </form>

                                    <div style="margin-top:8px">
                                        <span class="badge {{ $r->status->badge() }}">{{ $r->status->label() }}</span>

                                        @if ($r->notified_at)
                                            <span class="cell-empty">
                                                diberitahukan {{ \App\Support\Waktu::ringkas($r->notified_at) }}
                                            </span>

                                            <form method="POST" style="display:inline"
                                                  action="{{ route('admin.drama-request.renotify', $r->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-ghost btn-sm">Kirim ulang</button>
                                            </form>
                                        @elseif ($r->status->value === 'available')
                                            <span class="queue-error">belum diberitahukan</span>
                                        @endif
                                    </div>
                                </td>

                                <td>{{ \App\Support\Waktu::ringkas($r->created_at) }}</td>

                                <td class="col-actions">
                                    <form method="POST" action="{{ route('admin.drama-request.destroy', $r->id) }}"
                                          data-confirm
                                          data-confirm-title="Hapus permintaan?"
                                          data-confirm-message="Permintaan {{ $r->title }} akan dihapus permanen.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon" aria-label="Hapus">
                                            <x-web.home.icon name="trash" :size="14" />
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $daftar->links() }}
        @endif
    </section>

@endsection
