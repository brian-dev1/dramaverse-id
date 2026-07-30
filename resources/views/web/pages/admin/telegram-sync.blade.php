@extends('web.layouts.admin')

@section('title', 'Sinkron Telegram')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Menunggu"   :value="$counts['pending']"    icon="clock" />
        <x-admin.stat-card label="Diproses"   :value="$counts['processing']" icon="restore" />
        <x-admin.stat-card label="Tersinkron" :value="$counts['synced']"     icon="check" />
        <x-admin.stat-card label="Gagal"      :value="$counts['failed']"     icon="close" />
    </div>

    @if ($blocker)
        <section class="panel">
            <div class="panel-head"><h2>Belum bisa dipakai</h2></div>
            <div class="detail-body-admin monitor-alert">
                <p class="page-subtitle">{{ $blocker }}</p>
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Video episode</h2>
            <span class="panel-meta">
                Video dikirim SEKALI ke Telegram untuk mendapatkan file_id.
                Sesudah itu setiap pengiriman ke pengguna tidak memakai bandwidth bucket sama sekali.
            </span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.telegram-sync.index') }}" class="toolbar-search">
                <select name="status" class="control control-sm" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}" @selected($status === $s->value)>
                            {{ $s->label() }}
                        </option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-sm">Saring</button></noscript>
            </form>

            <div class="toolbar-actions">
                <form method="POST" action="{{ route('admin.telegram-sync.all') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-primary" @disabled($blocker !== null)>
                        <x-web.home.icon name="send" :size="14" />
                        Sinkronkan yang menunggu
                    </button>
                </form>
            </div>
        </div>

        @if ($videos->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Belum ada video episode. Unggah dulu lewat menu Episode, tombol "Unggah video".
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Episode</th>
                            <th>Ukuran</th>
                            <th>Status</th>
                            <th>file_id</th>
                            <th>Disinkron</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($videos as $video)
                            <tr>
                                <td>
                                    {{ $video->episode?->drama?->title ?? '—' }}
                                    <br>
                                    <span class="cell-empty">
                                        Episode {{ $video->episode?->episode_number ?? '?' }}
                                    </span>
                                </td>
                                <td>{{ $video->size_for_humans }}</td>
                                <td>
                                    <span class="badge {{ $video->sync_status->badge() }}">
                                        {{ $video->sync_status->label() }}
                                    </span>
                                    @if ($video->retry_count > 0)
                                        <br><span class="cell-empty">{{ $video->retry_count }}x dicoba</span>
                                    @endif
                                    @if ($video->last_error)
                                        <br><span class="queue-error">{{ $video->last_error }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($video->telegram_file_id)
                                        <span class="fm-key">{{ Str::limit($video->telegram_file_id, 22) }}</span>
                                    @else
                                        <span class="cell-empty">—</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $video->synced_at?->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="col-actions">
                                    @if ($video->sync_status->value === 'failed')
                                        <form method="POST"
                                              action="{{ route('admin.telegram-sync.retry', $video->id) }}"
                                              class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">
                                                <x-web.home.icon name="restore" :size="14" />
                                                Ulangi
                                            </button>
                                        </form>
                                    @elseif ($video->sync_status->value === 'pending')
                                        <form method="POST"
                                              action="{{ route('admin.telegram-sync.sync', $video->id) }}"
                                              class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn btn-sm" @disabled($blocker !== null)>
                                                <x-web.home.icon name="send" :size="14" />
                                                Sinkronkan
                                            </button>
                                        </form>
                                    @else
                                        <span class="cell-empty">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $videos->links() }}
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Catatan</h2></div>

        <div class="detail-body-admin">
            <dl class="settings-meta">
                <dt>Chat penyimpanan</dt>
                <dd>{{ $chatId ?: 'belum diisi' }}</dd>

                <dt>Batas unggah</dt>
                <dd>{{ config('telegram.upload_max_mb') }} MB — batas Bot API, bukan batas aplikasi</dd>
            </dl>

            <p class="page-subtitle">
                Video yang lebih besar dari batas itu akan ditolak Telegram sebelum dikirim.
                Jalan keluarnya memakai Local Bot API Server sendiri lewat
                <code>TELEGRAM_API_URL</code>, yang batasnya 2000 MB.
            </p>
        </div>
    </section>

@endsection
