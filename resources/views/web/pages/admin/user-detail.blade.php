@extends('web.layouts.admin')

@section('title', 'Pengguna: '.$user->display_name)

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>{{ $user->display_name }}</h2>
            <a href="{{ route('admin.user.index') }}" class="see-all">
                <x-web.home.icon name="arrow-left" :size="13" /> Semua pengguna
            </a>
        </div>

        <div class="detail-body-admin">
            <dl class="settings-meta">
                <dt>Telegram</dt>
                <dd>{{ $user->telegram_username ? '@'.$user->telegram_username : '—' }}</dd>

                <dt>ID Telegram</dt>
                <dd>{{ $user->telegram_id ?? '—' }}</dd>

                <dt>Bahasa</dt>
                <dd>{{ $user->telegram_language ?? '—' }}</dd>

                <dt>Status</dt>
                <dd>
                    <span class="badge {{ $user->is_active ? 'badge-on' : 'badge-off' }}">
                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                    </span>
                    @if ($user->is_banned)
                        <span class="badge badge-off">Diblokir</span>
                    @endif
                </dd>

                <dt>Terakhir masuk</dt>
                <dd>{{ \App\Support\Waktu::ringkas($user->last_login_at) }}</dd>

                <dt>Terakhir aktif</dt>
                <dd>{{ $user->last_seen_at?->diffForHumans() ?? '—' }}</dd>

                <dt>Bergabung</dt>
                <dd>{{ \App\Support\Waktu::ringkas($user->created_at) }}</dd>
            </dl>

            @can('admin.manage')
                @if ($user->id !== auth()->id() && ! $user->isRoot())
                    <div class="panel" style="margin-top:16px;">
                        <div class="panel-head"><h2>Akses admin</h2></div>

                        <div class="detail-body-admin">
                            @if ($user->is_admin)
                                <p class="page-subtitle">
                                    Pengguna ini <strong>admin</strong>, dengan role:
                                    <strong>{{ $user->roles->pluck('name')->join(', ') ?: 'belum ada role' }}</strong>.
                                    Aksesnya berlaku di panel maupun di perintah bot.
                                </p>

                                <form method="POST" action="{{ route('admin.user.demote', $user->id) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">Cabut status admin</button>
                                </form>
                            @else
                                <p class="page-subtitle">
                                    Jadikan pengguna ini admin agar bisa memakai perintah admin di bot
                                    Telegram. Pilih role-nya — role itulah yang menentukan apa saja
                                    yang boleh ia buka, di panel maupun di bot.
                                </p>

                                <form method="POST" action="{{ route('admin.user.promote', $user->id) }}">
                                    @csrf

                                    <div class="settings-meta" style="display:block;">
                                        @forelse ($roles as $role)
                                            <label style="display:block; margin:6px 0;">
                                                <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                                                       @checked(in_array($role->id, old('roles', [])))>
                                                {{ $role->name }}
                                                @if ($role->description)
                                                    <span class="page-subtitle">— {{ $role->description }}</span>
                                                @endif
                                            </label>
                                        @empty
                                            <p class="page-subtitle">
                                                Belum ada role. Buat dulu di menu Role, lalu kembali ke sini.
                                            </p>
                                        @endforelse
                                    </div>

                                    @error('roles')
                                        <p class="page-subtitle" style="color:var(--danger, #d33);">{{ $message }}</p>
                                    @enderror

                                    @if ($roles->isNotEmpty())
                                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;">
                                            Jadikan admin
                                        </button>
                                    @endif
                                </form>

                                <p class="page-subtitle" style="margin-top:10px;">
                                    Untuk pemantau Affiliate di bot (<code>/afiliasi</code>), role-nya harus
                                    memuat izin <strong>Kelola Pembayaran</strong>.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            @endcan

            @unless ($user->is_admin)
                <div class="detail-actions">
                    <form method="POST" action="{{ route('admin.user.active', $user->id) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">
                            {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.user.ban', $user->id) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">
                            {{ $user->is_banned ? 'Buka blokir' : 'Blokir' }}
                        </button>
                    </form>
                </div>
            @endunless
        </div>
    </section>

    <div class="stat-row">
        <div class="stat-card">
            <span class="stat-value">{{ number_format($user->watch_histories_count) }}</span>
            <span class="stat-label">Riwayat tontonan</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">{{ number_format($user->favorites_count) }}</span>
            <span class="stat-label">Favorit</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">{{ number_format($user->watchlists_count) }}</span>
            <span class="stat-label">Daftar saya</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">{{ number_format($subscriptions->count()) }}</span>
            <span class="stat-label">Langganan</span>
        </div>
    </div>

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head"><h2>Riwayat tontonan</h2></div>
            <table class="data-table">
                <thead><tr><th>Drama</th><th>Part</th><th>Terakhir</th></tr></thead>
                <tbody>
                    @forelse ($histories as $h)
                        <tr>
                            <td>{{ $h->drama?->title }}</td>
                            <td>{{ $h->episode?->episode_number ?? '—' }}</td>
                            <td>{{ $h->last_watched_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum pernah menonton.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Langganan</h2></div>
            <table class="data-table">
                <thead><tr><th>Paket</th><th>Status</th><th>Berakhir</th></tr></thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        <tr>
                            <td>{{ $sub->plan?->name ?? '—' }}</td>
                            <td><span class="badge badge-status">{{ ucfirst($sub->status) }}</span></td>
                            <td>{{ \App\Support\Waktu::ringkas($sub->expired_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum pernah berlangganan.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Favorit</h2></div>
            <table class="data-table">
                <thead><tr><th>Drama</th><th>Ditambahkan</th></tr></thead>
                <tbody>
                    @forelse ($favorites as $fav)
                        <tr>
                            <td>{{ $fav->drama?->title }}</td>
                            <td>{{ $fav->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada favorit.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Daftar saya</h2></div>
            <table class="data-table">
                <thead><tr><th>Drama</th><th>Ditambahkan</th></tr></thead>
                <tbody>
                    @forelse ($watchlists as $item)
                        <tr>
                            <td>{{ $item->drama?->title }}</td>
                            <td>{{ $item->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><span class="cell-empty">Belum ada daftar tonton.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    </div>

@endsection
