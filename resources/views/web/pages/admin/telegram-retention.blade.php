@extends('web.layouts.admin')

@section('title', 'Penarikan Video Premium')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Video premium terkirim" :value="$stats['total']"   icon="film" />
        <x-admin.stat-card label="Masih di chat"          :value="$stats['pending']" icon="clock" />
        <x-admin.stat-card label="Sudah ditarik"          :value="$stats['deleted']" icon="check" />
        <x-admin.stat-card label="Lewat 48 jam"           :value="$stats['too_old']" icon="alert" />
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Cara kerjanya</h2>
        </div>

        <div class="detail-body-admin">
            <p>
                Bot Telegram <strong>hanya bisa menghapus pesannya sendiri, dan hanya
                bila usia pesan itu kurang dari 48 jam</strong>. Ini batas dari Telegram,
                bukan keterbatasan panel ini — video yang lebih tua tidak akan pernah
                bisa ditarik lewat cara apa pun.
            </p>
            <p>
                Karena itu yang menentukan berhasil-tidaknya penarikan bukan tombol di
                halaman ini, melainkan <strong>masa hidup video</strong>. Dengan
                <code>TELEGRAM_VIDEO_TTL_HOURS</code> di bawah 48, hampir semua video
                sudah hilang sendiri sebelum sempat menjadi terlalu tua.
            </p>

            <dl class="settings-meta">
                <dt>Masa hidup video premium</dt>
                <dd>
                    @if ($config['ttl_hours'] > 0)
                        {{ $config['ttl_hours'] }} jam sejak dikirim
                    @else
                        <strong>Mati</strong> — video tidak hilang sendiri, dan sebagian
                        besar akan lewat 48 jam sebelum VIP-nya berakhir.
                    @endif
                </dd>

                <dt>Tarik saat VIP berakhir</dt>
                <dd>{{ $config['on_expire'] ? 'Ya' : 'Tidak' }}</dd>

                <dt>Gagal ditarik</dt>
                <dd>{{ $stats['failed'] }} (bot diblokir, chat dihapus, dan sejenisnya)</dd>
            </dl>

            <form method="POST" action="{{ route('admin.telegram-retention.run') }}">
                @csrf
                <button type="submit" class="btn btn-primary">
                    Jalankan penarikan sekarang
                </button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Riwayat pengiriman</h2>
            <span class="panel-meta">{{ $rows->total() }} baris</span>
        </div>

        <form method="GET" class="filter-row">
            <input type="search" name="q" value="{{ $filter['q'] ?? '' }}"
                   placeholder="ID / Telegram ID / nama pengguna">

            <select name="status">
                <option value="">Semua status</option>
                @foreach ([
                    'pending' => 'Masih di chat',
                    'deleted' => 'Terhapus',
                    'too_old' => 'Lewat 48 jam',
                    'failed'  => 'Gagal dihapus',
                    'skipped' => 'Dilewati (gratis)',
                ] as $nilai => $label)
                    <option value="{{ $nilai }}"
                        @selected(($filter['status'] ?? '') === $nilai)>{{ $label }}</option>
                @endforeach
            </select>

            <label>
                <input type="checkbox" name="premium" value="1"
                    @checked(($filter['premium'] ?? '') === '1')>
                Premium saja
            </label>

            <button type="submit" class="btn">Terapkan</button>
        </form>

        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Pengguna</th>
                        <th>Episode</th>
                        <th>Dikirim</th>
                        <th>Dijadwal hapus</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            {{ $row->user?->name ?? '—' }}
                            <small class="muted d-block">
                                ID {{ $row->user_id ?? '—' }} · chat {{ $row->chat_id }}
                            </small>
                        </td>
                        <td>
                            {{ $row->episode?->drama?->title ?? '—' }}
                            <small class="muted d-block">
                                Ep {{ $row->episode?->episode_number ?? '—' }}
                                @if ($row->is_premium)
                                    · <strong>VIP</strong>
                                @endif
                            </small>
                        </td>

                        {{-- title= berisi bentuk presisi supaya nilainya bisa disalin
                             tanpa ambigu saat menelusuri keluhan pengguna. --}}
                        <td title="{{ \App\Support\Waktu::presisi($row->sent_at) }}">
                            {{ $row->sent_at?->ringkas() ?? '—' }}
                        </td>

                        <td title="{{ \App\Support\Waktu::presisi($row->delete_after) }}">
                            {{ $row->delete_after?->ringkas() ?? '—' }}
                        </td>

                        <td>
                            {{ $row->statusLabel() }}
                            @if ($row->delete_error)
                                <small class="muted d-block">{{ $row->delete_error }}</small>
                            @endif
                        </td>

                        <td>
                            @if ($row->user_id && $row->delete_status === \App\Models\TelegramDelivery::PENDING)
                                <form method="POST"
                                      action="{{ route('admin.telegram-retention.user', $row->user_id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm">Tarik semua</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Belum ada pengiriman tercatat.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $rows->links() }}
    </section>

@endsection
