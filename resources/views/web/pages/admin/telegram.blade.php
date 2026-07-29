@extends('web.layouts.admin')

@section('title', 'Telegram')

@section('content')

    <div class="stat-row">
        <x-admin.stat-card label="Pengguna Telegram" :value="$stats['total']"  icon="send" />
        <x-admin.stat-card label="Aktif"            :value="$stats['active']" icon="user" />
        <x-admin.stat-card label="Diblokir"         :value="$stats['banned']" icon="shield" />
        <x-admin.stat-card label="Bergabung hari ini" :value="$stats['today']" icon="plus" />
    </div>

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head"><h2>Status bot</h2></div>

            <div class="detail-body-admin">
                @if ($bot)
                    <dl class="settings-meta">
                        <dt>Nama bot</dt>
                        <dd>{{ $bot['first_name'] ?? '—' }}</dd>

                        <dt>Username</dt>
                        <dd>{{ isset($bot['username']) ? '@'.$bot['username'] : '—' }}</dd>

                        <dt>ID bot</dt>
                        <dd>{{ $bot['id'] ?? '—' }}</dd>
                    </dl>
                @else
                    <p class="page-subtitle">
                        Tidak dapat menghubungi Telegram. Periksa <code>TELEGRAM_BOT_TOKEN</code>
                        di berkas <code>.env</code>, lalu jalankan <code>php artisan config:cache</code>.
                    </p>
                @endif
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Webhook</h2></div>

            <div class="detail-body-admin">
                @if ($webhook)
                    <dl class="settings-meta">
                        <dt>URL</dt>
                        <dd>{{ $webhook['url'] ?: 'belum didaftarkan' }}</dd>

                        <dt>Update tertunda</dt>
                        <dd>{{ $webhook['pending_update_count'] ?? 0 }}</dd>

                        <dt>Galat terakhir</dt>
                        <dd>
                            @if (! empty($webhook['last_error_message']))
                                <span class="badge badge-off">{{ $webhook['last_error_message'] }}</span>
                            @else
                                <span class="badge badge-on">Tidak ada</span>
                            @endif
                        </dd>
                    </dl>
                @else
                    <p class="page-subtitle">Informasi webhook tidak tersedia.</p>
                @endif
            </div>
        </section>

    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Kirim broadcast</h2>
            <span class="panel-meta">Dikirim lewat antrean — pastikan worker berjalan</span>
        </div>

        <form method="POST" action="{{ route('admin.telegram.broadcast') }}" class="admin-form broadcast-form">
            @csrf

            <x-admin.field name="audience" label="Penerima" type="select" required
                           :value="old('audience', 'all')"
                           :options="collect($audiences)->mapWithKeys(fn ($label, $key) => [
                               $key => $label.' ('.number_format($counts[$key] ?? 0).' orang)',
                           ])->all()" />

            <x-admin.field name="message" label="Isi pesan" type="textarea" :rows="7"
                           :value="old('message')" required
                           hint="Mendukung HTML sederhana: <b>, <i>, <a href>. Maksimal 4000 karakter." />

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <x-web.home.icon name="send" :size="14" />
                    Kirim broadcast
                </button>
            </div>
        </form>
    </section>

@endsection
