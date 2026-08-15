@extends('web.layouts.admin')

@section('title', 'Sinkron Telegram')

@section('content')

    {{--
        Kartu status: bot, webhook, antrean, dan sinkronisasi dalam satu
        pandangan. Semuanya dari TelegramHealthService, sumber yang sama
        dengan `telegram:auto health` yang dijalankan scheduler — supaya
        halaman ini tidak pernah mengatakan baik-baik saja sementara
        scheduler mengirim peringatan.
    --}}
    <div class="stat-row">
        <x-admin.stat-card label="Menunggu"   :value="$health['sync']['pending']"    icon="clock" />
        <x-admin.stat-card label="Diproses"   :value="$health['sync']['processing']" icon="restore" />
        <x-admin.stat-card label="Tersinkron" :value="$health['sync']['synced']"     icon="check" />
        <x-admin.stat-card label="Gagal"      :value="$health['sync']['failed']"     icon="close" />
    </div>

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head"><h2>Status</h2></div>

            <div class="detail-body-admin">
                <dl class="settings-meta">
                    <dt>Bot</dt>
                    <dd>
                        @if ($health['bot']['ok'])
                            <span class="badge badge-on">@{{ $health['bot']['username'] ?? '?' }}</span>
                            <span class="cell-empty">{{ $health['bot']['duration_ms'] ?? 0 }} ms</span>
                        @else
                            <span class="badge badge-off">tidak menjawab</span>
                            <br><span class="queue-error">{{ $health['bot']['error'] ?? '' }}</span>
                        @endif
                    </dd>

                    <dt>Webhook</dt>
                    <dd>
                        @if ($health['webhook']['ok'])
                            {{ $health['webhook']['url'] ?: 'belum didaftarkan' }}
                            <br><span class="cell-empty">
                                {{ $health['webhook']['pending'] }} update tertahan
                            </span>
                            @if (! empty($health['webhook']['last_error']))
                                <br><span class="queue-error">{{ $health['webhook']['last_error'] }}</span>
                            @endif
                        @else
                            <span class="badge badge-off">tidak terbaca</span>
                        @endif
                    </dd>

                    <dt>Antrean</dt>
                    <dd>
                        <code>{{ $health['queue']['queue'] }}</code>
                        pada {{ $health['queue']['connection'] }}
                        <br>
                        @if ($health['queue']['pending'] === null)
                            <span class="cell-empty">jumlah tidak terbaca dari driver ini</span>
                        @else
                            <span class="cell-empty">
                                {{ number_format($health['queue']['pending']) }} menunggu,
                                {{ number_format($health['queue']['failed'] ?? 0) }} gagal
                            </span>
                        @endif
                    </dd>

                    <dt>Tersangkut</dt>
                    <dd>
                        @if ($stats['stuck'] > 0)
                            <span class="badge badge-off">{{ $stats['stuck'] }} baris</span>
                            <br><span class="cell-empty">
                                Dilepaskan otomatis oleh <code>telegram:auto cleanup</code>.
                            </span>
                        @else
                            <span class="badge badge-on">tidak ada</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Statistik</h2></div>

            <div class="detail-body-admin">
                <dl class="settings-meta">
                    <dt>Total video</dt>
                    <dd>{{ number_format($stats['total']) }}</dd>

                    <dt>Sudah di Telegram</dt>
                    <dd>
                        {{ number_format($stats['synced']) }}
                        @if ($stats['total'] > 0)
                            <span class="cell-empty">
                                ({{ round($stats['synced'] / $stats['total'] * 100) }}%)
                            </span>
                        @endif
                    </dd>

                    <dt>Ukuran tersinkron</dt>
                    <dd>{{ number_format($stats['synced_size'] / 1073741824, 2) }} GB</dd>

                    <dt>Chat penyimpanan</dt>
                    <dd>{{ $chatId ?: 'belum diisi' }}</dd>

                    <dt>Batas unggah</dt>
                    <dd>{{ config('telegram.upload_max_mb') }} MB — batas Bot API</dd>

                    <dt>Otomatisasi</dt>
                    <dd>
                        Auto sync
                        <span class="badge {{ config('telegram.automation.auto_sync') ? 'badge-on' : 'badge-off' }}">
                            {{ config('telegram.automation.auto_sync') ? 'nyala' : 'mati' }}
                        </span>
                        Auto retry
                        <span class="badge {{ config('telegram.automation.auto_retry') ? 'badge-on' : 'badge-off' }}">
                            {{ config('telegram.automation.auto_retry') ? 'nyala' : 'mati' }}
                        </span>
                    </dd>
                </dl>
            </div>
        </section>

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
            <h2>Video part</h2>
            <span class="panel-meta">
                Video dikirim SEKALI ke Telegram untuk mendapatkan file_id. Sesudah itu
                pengiriman ke pengguna tidak memakai bandwidth bucket sama sekali.
            </span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.telegram-sync.index') }}" class="toolbar-search">
                <input type="search" name="q" value="{{ $q }}" class="control control-sm"
                       placeholder="Cari judul drama, nomor part, nama berkas">

                <select name="status" class="control control-sm">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}" @selected($status === $s->value)>
                            {{ $s->label() }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="control control-sm">
                    <option value="id"          @selected($sort === 'id')>Terbaru</option>
                    <option value="size"        @selected($sort === 'size')>Ukuran</option>
                    <option value="status"      @selected($sort === 'status')>Status</option>
                    <option value="synced_at"   @selected($sort === 'synced_at')>Waktu sinkron</option>
                    <option value="retry_count" @selected($sort === 'retry_count')>Jumlah percobaan</option>
                </select>

                <select name="dir" class="control control-sm">
                    <option value="desc" @selected($dir === 'desc')>Turun</option>
                    <option value="asc"  @selected($dir === 'asc')>Naik</option>
                </select>

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" />
                    Terapkan
                </button>
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
                    Tidak ada video yang cocok. Unggah dulu lewat menu Part, tombol "Unggah video".
                </p>
            </div>
        @else
            {{--
                Form aksi massal berada DI LUAR tabel dan dihubungkan lewat
                atribut `form`. Form yang melingkupi tabel akan bersarang
                dengan form tombol per baris, dan parser HTML membuang yang
                bersarang — bug yang masih tercatat di STATUS.md untuk modul
                CRUD lain.
            --}}
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check">
                                {{--
                                    Hanya mencentang yang BELUM tersinkron. Video yang
                                    sudah punya file_id tidak perlu dikirim ulang — itu
                                    cuma menghabiskan kuota dan menghasilkan file_id
                                    kedua untuk isi yang sama. Untuk memilih yang sudah
                                    tersinkron (misalnya buat Verifikasi file_id), ada
                                    tombolnya sendiri di baris aksi massal.
                                --}}
                                <input type="checkbox" data-check-all
                                       title="Centang semua yang belum tersinkron">
                            </th>
                            <th>Part</th>
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
                                <td class="col-check">
                                    <input type="checkbox" form="bulk-form" name="ids[]"
                                           value="{{ $video->id }}" data-check-item
                                           data-syncable="{{ $video->sync_status->canStart() ? '1' : '0' }}">
                                </td>
                                <td>
                                    {{ $video->episode?->drama?->title ?? '—' }}
                                    <br>
                                    <span class="cell-empty">
                                        Part {{ $video->episode?->episode_number ?? '?' }}
                                    </span>
                                </td>
                                <td>{{ $video->size_for_humans }}</td>
                                <td>
                                    <span class="badge {{ $video->admin_status_badge }}">
                                        {{ $video->admin_status_label }}
                                    </span>
                                    @if ($video->retry_count > 0)
                                        <br><span class="cell-empty">{{ $video->retry_count }}x dicoba</span>
                                    @endif

                                    @if ($video->hasActiveIssue())
                                        <br><span class="queue-error">
                                            ⚠ Masih ada masalah: {{ $video->issue_message }}
                                        </span>
                                        @if ($video->issue_detected_at)
                                            <br><span class="cell-empty">
                                                Terdeteksi {{ \App\Support\Waktu::ringkas($video->issue_detected_at) }}.
                                                Verifikasi file_id setelah perbaikan untuk menutup masalah.
                                            </span>
                                        @endif
                                    @elseif ($video->isSyncedToTelegram())
                                        <br><span class="cell-empty">✓ Selesai, tidak ada masalah aktif.</span>
                                    @elseif ($video->last_error)
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
                                <td>{{ \App\Support\Waktu::ringkas($video->synced_at) }}</td>
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

            <form method="POST" action="{{ route('admin.telegram-sync.bulk') }}"
                  id="bulk-form" class="bulk-bar">
                @csrf

                <span class="panel-meta">
                    Aksi massal, maksimal {{ $bulkMax }} video sekali jalan. Semuanya lewat antrean.
                    <br>
                    <span data-check-count>Belum ada yang dicentang.</span>
                </span>

                <button type="button" class="btn btn-sm btn-ghost" data-check-synced>
                    Centang yang tersinkron
                </button>

                <button type="button" class="btn btn-sm btn-ghost" data-check-none>
                    Bersihkan
                </button>

                <button type="submit" name="aksi" value="sync" class="btn btn-sm" @disabled($blocker !== null)>
                    <x-web.home.icon name="send" :size="14" /> Bulk Sync
                </button>

                <button type="submit" name="aksi" value="retry" class="btn btn-sm">
                    <x-web.home.icon name="restore" :size="14" /> Bulk Retry
                </button>

                <button type="submit" name="aksi" value="cancel" class="btn btn-sm btn-danger">
                    <x-web.home.icon name="close" :size="14" /> Bulk Cancel
                </button>

                <button type="submit" name="aksi" value="refresh" class="btn btn-sm">
                    <x-web.home.icon name="restore" :size="14" /> Refresh Status
                </button>

                <button type="submit" name="aksi" value="verify" class="btn btn-sm">
                    <x-web.home.icon name="check" :size="14" /> Verifikasi file_id
                </button>
            </form>

            {{ $videos->links() }}
        @endif
    </section>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const semua = document.querySelector('[data-check-all]');
        const items = Array.from(document.querySelectorAll('[data-check-item]'));

        if (!semua || !items.length) {
            return;
        }

        const tombolTersinkron = document.querySelector('[data-check-synced]');
        const tombolBersih     = document.querySelector('[data-check-none]');
        const penghitung       = document.querySelector('[data-check-count]');

        // Yang boleh dikirim ulang hanyalah PENDING dan FAILED. PROCESSING
        // sedang berjalan, SYNCED sudah punya file_id — keduanya sengaja
        // dikeluarkan dari "centang semua".
        const belumSinkron = items.filter((el) => el.dataset.syncable === '1');
        const sudahSinkron = items.filter((el) => el.dataset.syncable !== '1');

        const hitung = () => {
            const dipilih = items.filter((el) => el.checked);

            const dariBelum = dipilih.filter((el) => el.dataset.syncable === '1').length;
            const dariSudah = dipilih.length - dariBelum;

            if (!dipilih.length) {
                penghitung.textContent = 'Belum ada yang dicentang.';
            } else {
                let teks = `${dipilih.length} dicentang`;

                if (dariSudah > 0) {
                    teks += ` — ${dariBelum} belum tersinkron, ${dariSudah} sudah`;
                }

                penghitung.textContent = teks + '.';
            }

            // Kotak header hanya bicara soal yang belum tersinkron, jadi
            // keadaannya pun dihitung dari kelompok itu saja.
            const tercentang = belumSinkron.filter((el) => el.checked).length;

            semua.checked = belumSinkron.length > 0 && tercentang === belumSinkron.length;

            semua.indeterminate = tercentang > 0 && tercentang < belumSinkron.length;
        };

        semua.addEventListener('change', () => {
            const nyalakan = semua.checked;

            belumSinkron.forEach((el) => {
                el.checked = nyalakan;
            });

            hitung();
        });

        items.forEach((el) => el.addEventListener('change', hitung));

        if (tombolTersinkron) {
            // Untuk Verifikasi file_id dan Refresh Status, yang justru
            // dibutuhkan adalah video yang SUDAH tersinkron.
            tombolTersinkron.addEventListener('click', () => {
                sudahSinkron.forEach((el) => {
                    el.checked = true;
                });

                hitung();
            });

            tombolTersinkron.disabled = sudahSinkron.length === 0;
        }

        if (tombolBersih) {
            tombolBersih.addEventListener('click', () => {
                items.forEach((el) => {
                    el.checked = false;
                });

                hitung();
            });
        }

        if (!belumSinkron.length) {
            semua.disabled = true;
            semua.title = 'Tidak ada video yang belum tersinkron di halaman ini.';
        }

        hitung();
    });
</script>
@endpush
