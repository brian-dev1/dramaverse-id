<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') — DramaVerse ID</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">

@php
    $menu = [
        ['group' => 'Utama', 'items' => [
            ['route' => 'admin.dashboard',    'icon' => 'chart',    'label' => 'Dashboard'],
        ]],
        ['group' => 'Katalog', 'items' => [
            ['route' => 'admin.drama.index',   'icon' => 'film',   'label' => 'Drama',   'can' => 'drama.manage'],
            ['route' => 'admin.episode.index', 'icon' => 'list',   'label' => 'Episode', 'can' => 'episode.manage'],
            ['route' => 'admin.genre.index',   'icon' => 'tag',    'label' => 'Genre',   'can' => 'taxonomy.manage'],
            ['route' => 'admin.country.index', 'icon' => 'globe',  'label' => 'Negara',  'can' => 'taxonomy.manage'],
            ['route' => 'admin.banner.index',  'icon' => 'image',  'label' => 'Banner',  'can' => 'taxonomy.manage'],
            // Ditempatkan di kelompok Katalog, bukan Sistem: yang membukanya
            // adalah orang yang baru saja mengunggah video, bukan orang yang
            // sedang mengurus server. `episode.manage` diterima sebagai
            // alternatif dengan alasan yang sama seperti route-nya — izin
            // `upload.view` belum ada sampai RoleSeeder dijalankan ulang.
            ['route' => 'admin.upload.index',  'icon' => 'clock',  'label' => 'Upload Queue', 'can' => ['upload.view', 'episode.manage']],
            // Video Inbox: berkas yang sudah didorong worker Telegram ke
            // storage dan menunggu dipasangkan ke episode. Diletakkan tepat
            // setelah Upload Queue karena satu alur kerja.
            ['route' => 'admin.video-inbox.index', 'icon' => 'inbox', 'label' => 'Video Inbox', 'can' => 'episode.manage'],
            // Batch Upload duduk di sebelah Upload Queue karena keduanya satu
            // alur: unggah banyak berkas di sini, lihat nasibnya di sana.
            ['route' => 'admin.batch.form',    'icon' => 'plus',   'label' => 'Batch Upload', 'can' => ['upload.manage', 'episode.manage']],
        ]],
        ['group' => 'Anggota', 'items' => [
            ['route' => 'admin.membership.index',  'icon' => 'card',  'label' => 'Membership', 'can' => 'membership.manage'],
            ['route' => 'admin.subscription.index','icon' => 'card',  'label' => 'Langganan',  'can' => 'membership.manage'],
            ['route' => 'admin.user.index',        'icon' => 'users', 'label' => 'Pengguna',   'can' => 'user.view'],
            ['route' => 'admin.admin-account.index', 'icon' => 'users', 'label' => 'Akun Admin', 'can' => 'admin.manage'],
            ['route' => 'admin.telegram',          'icon' => 'send',  'label' => 'Telegram',   'can' => 'telegram.manage'],
            ['route' => 'admin.telegram-menu.index', 'icon' => 'list', 'label' => 'Menu Telegram', 'can' => 'telegram.manage'],
            ['route' => 'admin.telegram-sync.index', 'icon' => 'film', 'label' => 'Sinkron Telegram', 'can' => 'telegram.manage'],
            ['route' => 'admin.telegram-log.index',  'icon' => 'file', 'label' => 'Log Telegram',    'can' => 'telegram.manage'],
            ['route' => 'admin.invoice.index',           'icon' => 'card',     'label' => 'Tagihan',          'can' => 'membership.manage'],
            ['route' => 'admin.payment-provider.index',  'icon' => 'settings', 'label' => 'Metode Bayar',     'can' => 'membership.manage'],
            ['route' => 'admin.payment-log.index',       'icon' => 'file',     'label' => 'Log Pembayaran',   'can' => 'membership.manage'],
            ['route' => 'admin.monitoring.index',    'icon' => 'activity', 'label' => 'Monitoring',  'can' => 'setting.manage'],
            ['route' => 'admin.system-log.index',    'icon' => 'file', 'label' => 'Log Sistem',      'can' => 'log.view'],
        ]],
        ['group' => 'Sistem', 'items' => [
            ['route' => 'admin.analytics',   'icon' => 'chart',    'label' => 'Analytics',    'can' => 'report.view'],
            ['route' => 'admin.report',      'icon' => 'file',     'label' => 'Laporan',      'can' => 'report.view'],
            ['route' => 'admin.logs.index',  'icon' => 'shield',   'label' => 'Log',          'can' => 'log.view'],
            ['route' => 'admin.role.index',  'icon' => 'shield',   'label' => 'Peran & Izin', 'can' => 'role.manage'],
            // `can` berupa array berarti salah satu izin sudah cukup. Ini
            // menyamai middleware route-nya: `storage.view` belum ada di
            // database sampai RoleSeeder dijalankan ulang, jadi tanpa
            // `setting.manage` sebagai alternatif menu ini akan tersembunyi
            // di server yang baru di-deploy.
            ['route' => 'admin.storage.index', 'icon' => 'database', 'label' => 'Storage Manager', 'can' => ['storage.view', 'setting.manage']],
            // Nama route-nya `admin.storage-monitor.*`, bukan
            // `admin.storage.monitor.*`, supaya penanda aktif Storage Manager
            // di bawah — yang memakai `routeIs('admin.storage.*')` — tidak
            // ikut menyala ketika halaman ini yang sedang dibuka.
            ['route' => 'admin.storage-monitor.index', 'icon' => 'activity', 'label' => 'Storage Monitoring', 'can' => ['storage.view', 'setting.manage']],
            ['route' => 'admin.files.index',  'icon' => 'file',     'label' => 'File Manager', 'can' => ['storage.view', 'setting.manage']],
            ['route' => 'admin.settings',    'icon' => 'settings', 'label' => 'Pengaturan',   'can' => 'setting.manage'],
        ]],
    ];
@endphp

<div class="admin-shell" data-shell>

    <aside class="admin-sidebar" data-sidebar>

        <div class="admin-brand">
            <a href="{{ route('admin.dashboard') }}" class="logo">
                DramaVerse<span class="dot"></span><span class="id">ADMIN</span>
            </a>
            <button type="button" class="btn-icon sidebar-toggle" data-sidebar-toggle
                    aria-label="Sembunyikan menu">
                <x-web.home.icon name="menu" :size="17" />
            </button>
        </div>

        <nav class="admin-nav" aria-label="Menu admin">
            @foreach ($menu as $section)
                @php
                    // Sembunyikan seluruh kelompok bila tidak ada satu pun
                    // menu di dalamnya yang boleh diakses.
                    //
                    // `can` boleh berupa string atau array. Array berarti salah
                    // satu izin sudah cukup — semantik yang sama dengan
                    // middleware `permission:a,b` pada route-nya.
                    $visible = collect($section['items'])->filter(function ($i) {
                        if (! isset($i['can'])) {
                            return true;
                        }

                        $user = auth()->user();

                        return $user !== null && collect((array) $i['can'])
                            ->contains(fn ($izin) => $user->can($izin));
                    });
                @endphp

                @continue($visible->isEmpty())

                <p class="admin-nav-group">{{ $section['group'] }}</p>

                @foreach ($visible as $item)
                    @php
                        $base   = Str::beforeLast($item['route'], '.');
                        $active = request()->routeIs($item['route'])
                            || (Str::endsWith($item['route'], '.index') && request()->routeIs($base.'.*'));
                    @endphp
                    <a href="{{ route($item['route']) }}" class="{{ $active ? 'active' : '' }}">
                        <x-web.home.icon :name="$item['icon']" :size="17" />
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" class="admin-logout">
            @csrf
            <button type="submit">
                <x-web.home.icon name="logout" :size="16" />
                <span>Keluar</span>
            </button>
        </form>

    </aside>

    <div class="admin-main">

        <header class="admin-topbar">
            <button type="button" class="btn-icon sidebar-open" data-sidebar-toggle
                    aria-label="Tampilkan menu">
                <x-web.home.icon name="menu" :size="18" />
            </button>

            <h1>@yield('title', 'Admin')</h1>

            <div class="admin-topbar-right">
                <a href="{{ route('web.home') }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener">
                    Lihat situs
                </a>
                <span class="avatar" title="{{ auth()->user()?->name }}">
                    {{ auth()->user()?->initial }}
                </span>
            </div>
        </header>

        @if (session('status'))
            <div class="toast toast-success" role="status" data-toast>
                <x-web.home.icon name="check" :size="15" />
                {{ session('status') }}
            </div>
        @endif

        {{-- Penolakan yang BUKAN kesalahan isian form: mis. provider default
             yang tidak boleh dihapus, atau slug yang bentrok saat pemulihan.
             Sebelumnya pesan seperti ini tidak punya saluran sendiri, sehingga
             satu-satunya pilihan adalah session('status') — yang dirender
             hijau bercentang di bawah ini, seolah tindakannya berhasil. --}}
        @if (session('error'))
            <div class="toast toast-error" role="alert" data-toast>
                <x-web.home.icon name="close" :size="15" />
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="toast toast-error" role="alert" data-toast>
                <x-web.home.icon name="close" :size="15" />
                {{ $errors->count() }} isian belum benar. Periksa kembali form di bawah.
            </div>
        @endif

        <div class="admin-content">

            {{--
                Hasil tindakan yang terlalu penting untuk sebuah toast.

                Toast di atas menghilang sendiri setelah 4 detik. Itu tepat
                untuk "berhasil disimpan", tapi salah untuk hasil yang harus
                dibaca dan ditindaklanjuti — pesan galat Test Connection bisa
                sepanjang satu paragraf, dan justru di situlah petunjuknya.

                Bentuknya sengaja umum (judul, berhasil/gagal, satu baris
                keterangan, pesan, petunjuk) supaya bisa dipakai modul lain,
                bukan hanya storage.
            --}}
            @if ($detail = session('detail'))
                <section class="panel" role="{{ ($detail['ok'] ?? false) ? 'status' : 'alert' }}">
                    <div class="panel-head">
                        <h2>{{ $detail['title'] ?? 'Hasil' }}</h2>
                        <span class="badge {{ ($detail['ok'] ?? false) ? 'badge-on' : 'badge-off' }}">
                            {{ ($detail['ok'] ?? false) ? 'Berhasil' : 'Gagal' }}
                        </span>
                    </div>

                    <div class="detail-body-admin">
                        @if (! empty($detail['meta']))
                            <p class="panel-meta">{{ $detail['meta'] }}</p>
                        @endif

                        @if (! empty($detail['message']))
                            <p class="{{ ($detail['ok'] ?? false) ? 'field-hint' : 'field-error' }}">
                                {{ $detail['message'] }}
                            </p>
                        @endif

                        @if (! empty($detail['hint']))
                            <p class="field-hint">{{ $detail['hint'] }}</p>
                        @endif
                    </div>
                </section>
            @endif

            @yield('content')
        </div>

    </div>
</div>

{{-- Dialog konfirmasi, dikendalikan resources/js/admin.js --}}
<div class="modal" data-modal hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <h2 id="modal-title" data-modal-title>Konfirmasi</h2>
        <p data-modal-message></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Batal</button>
            <button type="button" class="btn btn-danger" data-modal-confirm>Hapus</button>
        </div>
    </div>
</div>

@stack('scripts')

{{-- Chart.js hanya dimuat bila halaman benar-benar punya grafik. --}}
@if (! empty($chartsOnPage ?? false) || request()->routeIs('admin.dashboard', 'admin.report'))
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
@endif
</body>
</html>
