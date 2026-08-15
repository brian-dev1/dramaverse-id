@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    {{--
        Tidak ada aksi massal di halaman ini, jadi tidak ada form yang
        membungkus tabel. Itu disengaja: form bulk yang melingkupi tabel
        membuat form tombol per baris menjadi bersarang, dan parser HTML
        membuang tag <form> bersarang — persis bug yang tercatat di STATUS.md
        untuk modul-modul CRUD. Setiap tombol di sini berdiri di form-nya
        sendiri, dan tidak ada satu pun yang berada di dalam form lain.
    --}}
    <form method="GET" class="admin-toolbar">
        <div class="toolbar-search">
            <x-web.home.icon name="search" :size="15" />
            <input type="search" name="q" value="{{ $keyword }}"
                   placeholder="Cari nama berkas, judul drama, atau uuid..." class="control">
        </div>

        <select name="status" class="control control-sm">
            <option value="">Status: semua</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-ghost btn-sm">Terapkan</button>

        @if ($keyword !== '' || $status !== '')
            <a href="{{ route('admin.upload.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif

        <div class="toolbar-actions">
            <a href="{{ route('admin.episode.video.form') }}" class="btn btn-primary btn-sm">
                <x-web.home.icon name="plus" :size="14" />
                Unggah video
            </a>
        </div>
    </form>

    {{--
        Driver `sync` menjalankan job di dalam request yang sama. Seluruh
        tujuan antrean hilang, dan yang membingungkan: tidak ada satu pun
        pesan galat. Pekerjaan langsung berstatus Berhasil, dan orang akan
        menyimpulkan antreannya bekerja luar biasa cepat.
    --}}
    @if ($sync)
        <section class="panel" role="alert">
            <div class="panel-head">
                <h2>Antrean berjalan sinkron</h2>
                <span class="badge badge-off">Perlu diperbaiki</span>
            </div>

            <div class="detail-body-admin">
                <p class="field-error">
                    Koneksi antrean <code>{{ $connection }}</code> memakai driver
                    <code>sync</code>, yang menjalankan pekerjaan di dalam request
                    yang sama. Unggahan tetap memblokir permintaan admin, dan status
                    Menunggu tidak akan pernah terlihat.
                </p>
                <p class="field-hint">
                    Ubah <code>QUEUE_CONNECTION=database</code> di <code>.env</code>,
                    lalu jalankan <code>php artisan config:cache</code> dan nyalakan
                    worker antrean.
                </p>
            </div>
        </section>
    @endif

    <p class="queue-note">
        <x-web.home.icon name="database" :size="13" />
        Pekerjaan dikirim ke koneksi <strong>{{ $connection }}</strong>, antrean
        <strong>{{ $queueName }}</strong>. Worker harus mendengarkan antrean itu —
        <code>php artisan queue:work --queue={{ $queueName }},default</code>.
        Kalau tidak, pekerjaan akan tetap berstatus Menunggu tanpa pesan galat di mana pun.
    </p>

    @if ($jobs->isEmpty())

        <div class="empty-state">
            <h3>Belum ada pekerjaan unggah</h3>
            <p>
                @if ($keyword !== '' || $status !== '')
                    Tidak ada pekerjaan yang cocok dengan pencarian Anda.
                @else
                    Riwayat akan terisi sendiri begitu ada video part yang diunggah.
                @endif
            </p>

            <a href="{{ route('admin.episode.video.form') }}" class="btn btn-primary">
                Unggah video part
            </a>
        </div>

    @else

        <div class="table-wrap">
            <table class="data-table" data-upload-queue
                   data-status-url="{{ route('admin.upload.show', ['uuid' => '00000000-0000-0000-0000-000000000000']) }}">
                <thead>
                    <tr>
                        <th>Berkas</th>
                        <th>Part</th>
                        <th>Tujuan</th>
                        <th>Ukuran</th>
                        <th>Status</th>
                        <th>Diantre</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($jobs as $job)
                        <tr data-job="{{ $job->uuid }}"
                            data-final="{{ $job->status->isFinal() ? '1' : '' }}">

                            <td>
                                {{ $job->original_filename }}
                                <span class="asset-sub">{{ $job->creator?->name ?: 'Pengunggah tidak diketahui' }}</span>
                            </td>

                            <td>{{ $job->target_label }}</td>

                            <td>{{ $job->target_storage }}</td>

                            <td>{{ $job->size_for_humans }}</td>

                            <td>
                                <span class="badge badge-status {{ $job->status->badgeClass() }}" data-status-cell>
                                    {{ $job->status->label() }}
                                </span>

                                @if ($job->attempts > 1)
                                    <span class="asset-sub">percobaan ke-{{ $job->attempts }}</span>
                                @endif
                            </td>

                            <td>
                                {{ \App\Support\Waktu::ringkas($job->queued_at) }}
                            </td>

                            <td class="col-actions">

                                <button type="button" class="btn-icon" data-detail="{{ $job->uuid }}"
                                        title="Rincian dan log" aria-label="Rincian dan log">
                                    <x-web.home.icon name="file" :size="15" />
                                </button>

                                @if ($job->isRetryable())
                                    <form method="POST" action="{{ route('admin.upload.retry', ['uuid' => $job->uuid]) }}"
                                          class="inline-form">
                                        @csrf
                                        <button type="submit" class="btn-icon"
                                                title="Ulangi" aria-label="Ulangi">
                                            <x-web.home.icon name="restore" :size="15" />
                                        </button>
                                    </form>
                                @endif

                                @if ($job->isCancellable())
                                    <form method="POST" action="{{ route('admin.upload.cancel', ['uuid' => $job->uuid]) }}"
                                          class="inline-form">
                                        @csrf
                                        <button type="submit" class="btn-icon"
                                                title="Batalkan" aria-label="Batalkan">
                                            <x-web.home.icon name="close" :size="15" />
                                        </button>
                                    </form>
                                @endif

                                {{--
                                    Hapus hanya untuk pekerjaan yang sudah
                                    selesai. Menghapus baris yang masih
                                    mengantre meninggalkan job di tabel `jobs`
                                    yang menunjuk baris yang tidak ada lagi.
                                --}}
                                @if ($job->status->isFinal())
                                    <x-admin.confirm
                                        :action="route('admin.upload.destroy', ['uuid' => $job->uuid])"
                                        title="Hapus riwayat unggahan ini?"
                                        message="Baris riwayat dan berkas sementaranya dihapus. Video yang sudah tersimpan di storage provider TIDAK ikut terhapus." />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">{{ $jobs->links() }}</div>

        {{--
            Rincian satu pekerjaan, diisi JS saat tombol rincian ditekan.

            Panel yang menetap, bukan toast: pesan galat dari SDK penyimpanan
            bisa sepanjang satu paragraf, dan justru di situlah petunjuknya.
            Alasan yang sama dipakai hasil Test Connection di Sprint 7.3.
        --}}
        <section class="panel queue-detail" data-detail-panel hidden>
            <div class="panel-head">
                <h2 data-detail-title>Rincian pekerjaan</h2>
                <button type="button" class="btn-icon" data-detail-close
                        title="Tutup" aria-label="Tutup">
                    <x-web.home.icon name="close" :size="15" />
                </button>
            </div>

            <div class="detail-body-admin">
                <p class="panel-meta" data-detail-meta></p>
                <p class="field-error queue-error" data-detail-error hidden></p>

                <ol class="queue-log" data-detail-log></ol>
            </div>
        </section>

    @endif

@endsection
