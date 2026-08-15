@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    {{--
        Driver `sync` menjalankan job di dalam request yang sama. Untuk batch,
        akibatnya lebih buruk daripada di unggahan satuan: dua puluh berkas
        dikirim ke provider satu per satu di dalam request peramban, dan
        halamannya menggantung sampai semuanya selesai.
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
                    <code>sync</code>. Setiap berkas akan dikirim ke storage provider
                    di dalam request-nya sendiri, bukan di latar belakang — batch
                    berisi dua puluh berkas akan menahan peramban sampai selesai.
                </p>
                <p class="field-hint">
                    Ubah <code>QUEUE_CONNECTION=database</code> di <code>.env</code>,
                    jalankan <code>php artisan config:cache</code>, lalu nyalakan worker.
                </p>
            </div>
        </section>
    @endif

    {{--
        Satu form, dikirim lewat XHR per berkas. Atribut data-* di bawah adalah
        kontrak antara halaman ini dan modul batchUpload() di
        resources/js/admin.js.

        Berbeda dari halaman unggah satuan, di sini TIDAK ada jalur cadangan
        tanpa JavaScript. Sebabnya jujur saja: pemetaan berkas ke episode dan
        pengiriman satu-per-satu memang tidak mungkin tanpa JavaScript, dan
        form yang tampak bisa dikirim tetapi hanya mengunggah satu berkas
        pertama akan lebih menyesatkan daripada tombol yang mati.
    --}}
    <form method="POST" action="{{ route('admin.batch.store') }}"
          class="admin-form" enctype="multipart/form-data"
          data-batch-upload
          data-episodes-url="{{ route('admin.episode.video.episodes', ['drama' => 0]) }}"
          data-status-url="{{ route('admin.batch.status', ['batch' => '00000000-0000-0000-0000-000000000000']) }}"
          data-queue-url="{{ route('admin.upload.index') }}">
        @csrf

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Tujuan</h2>

                <div class="field">
                    <label>Jenis unggahan <span class="field-required" aria-hidden="true">*</span></label>

                    <div class="radio-row">
                        <label class="checkbox-item">
                            <input type="radio" name="kind" value="video" data-kind checked>
                            Video part
                        </label>

                        <label class="checkbox-item">
                            <input type="radio" name="kind" value="asset" data-kind>
                            Aset drama (gambar &amp; subtitle)
                        </label>
                    </div>

                    <p class="field-hint">
                        Satu batch hanya untuk satu jenis. Mencampur video dan gambar
                        dalam satu batch berarti tiap berkas perlu tujuannya sendiri,
                        dan yang salah petakan baru ketahuan setelah terunggah.
                    </p>
                </div>

                <div class="field">
                    <label for="field-drama_id">
                        Drama <span class="field-required" aria-hidden="true">*</span>
                    </label>

                    <select id="field-drama_id" name="drama_id" class="control" data-drama required>
                        <option value="">— pilih drama —</option>
                        @foreach ($dramas as $drama)
                            <option value="{{ $drama->id }}">{{ $drama->title }}</option>
                        @endforeach
                    </select>

                    <p class="field-hint" data-drama-note>
                        Untuk video, daftar partnya menyusul setelah drama dipilih.
                    </p>
                </div>

                {{-- Hanya untuk jenis aset --}}
                <div class="field" data-asset-wrap hidden>
                    <label for="field-asset_type">
                        Jenis aset <span class="field-required" aria-hidden="true">*</span>
                    </label>

                    <select id="field-asset_type" name="asset_type" class="control" data-asset-type>
                        @foreach ($assetTypes as $value => $meta)
                            <option value="{{ $value }}"
                                    data-multiple="{{ $meta['multiple'] ? '1' : '' }}"
                                    data-max-kb="{{ $meta['max_kb'] }}"
                                    data-extensions="{{ implode(',', $meta['extensions']) }}">
                                {{ $meta['label'] }}{{ $meta['multiple'] ? ' — menerima banyak berkas' : ' — hanya satu berkas' }}
                            </option>
                        @endforeach
                    </select>

                    <p class="field-hint">
                        Hanya <strong>Galeri</strong> yang menerima banyak berkas. Jenis
                        lain hanya boleh punya satu berkas per drama — memilih sepuluh
                        gambar untuk Poster berarti sembilan di antaranya akan menimpa
                        yang sebelumnya, jadi halaman ini menolaknya sebelum dikirim.
                    </p>
                </div>
            </section>

            <section class="form-card">
                <h2>Penyimpanan</h2>

                <div class="field">
                    <label>Storage Mode <span class="field-required" aria-hidden="true">*</span></label>

                    <div class="radio-row">
                        <label class="checkbox-item">
                            <input type="radio" name="storage_mode" value="auto" data-mode checked>
                            Auto
                        </label>

                        <label class="checkbox-item">
                            <input type="radio" name="storage_mode" value="manual" data-mode>
                            Manual
                        </label>
                    </div>

                    <p class="field-hint">
                        @if ($autoTarget)
                            Auto mengirim ke provider default: <strong>{{ $autoTarget }}</strong>.
                        @else
                            Auto belum bisa dipakai — belum ada provider default yang aktif.
                            Tandai satu provider sebagai default di Storage Manager.
                        @endif
                    </p>
                </div>

                <div class="field" data-provider-wrap hidden>
                    <label for="field-storage_provider_id">
                        Storage Provider <span class="field-required" aria-hidden="true">*</span>
                    </label>

                    <select id="field-storage_provider_id" name="storage_provider_id"
                            class="control" data-provider>
                        <option value="">— pilih provider —</option>
                        @foreach ($providers as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <p class="field-hint">
                        @if ($providers === [])
                            Belum ada provider yang memenuhi syarat. Provider harus aktif,
                            lengkap, dan sudah lolos Test Connection.
                        @else
                            Seluruh berkas dalam batch ini dikirim ke provider yang sama.
                        @endif
                    </p>
                </div>

                <p class="field-hint">
                    Pekerjaan dikirim ke koneksi <strong>{{ $connection }}</strong>,
                    antrean <strong>{{ $queueName }}</strong>. Worker harus
                    mendengarkan antrean itu.
                </p>
            </section>
        </div>

        {{-- Area seret-lepas --}}
        <section class="form-card upload upload-video" data-dropzone>
            <h2>Berkas</h2>

            <div class="upload-body">
                <input type="file" name="files[]" class="upload-input" multiple
                       data-files
                       accept="{{ collect($videoExtensions)->map(fn ($e) => '.'.$e)->implode(',') }}">

                <p class="upload-empty" data-dropzone-hint>
                    Seret berkas ke sini, atau klik untuk memilih. Boleh banyak sekaligus.
                </p>
            </div>

            <p class="field-error" data-batch-error hidden></p>
        </section>

        {{-- Daftar berkas terpilih beserta tujuannya --}}
        <section class="panel batch-panel" data-batch-panel hidden>
            <div class="panel-head">
                <h2>Berkas terpilih</h2>
                <span class="badge badge-pending" data-batch-count>0 berkas</span>
            </div>

            <div class="detail-body-admin">

                <p class="field-hint" data-batch-note>
                    Untuk video, nomor part ditebak dari nama berkas — periksa dan
                    perbaiki sebelum mengunggah. Tebakan yang salah akan mengganti video
                    part yang keliru.
                </p>

                <ol class="batch-files" data-batch-list></ol>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" data-batch-submit>
                        <x-web.home.icon name="plus" :size="15" />
                        Unggah semua
                    </button>

                    <button type="button" class="btn btn-ghost" data-batch-clear>
                        Kosongkan daftar
                    </button>
                </div>

                <p class="batch-summary" data-batch-summary hidden></p>
            </div>
        </section>
    </form>

    <p class="queue-note">
        <x-web.home.icon name="clock" :size="13" />
        Tiap berkas menjadi satu pekerjaan antrean tersendiri. Kegagalan satu
        berkas tidak menghentikan yang lain — baik saat diterima maupun saat
        dikirim ke provider. Nasib tiap pekerjaan bisa dilihat di
        <a href="{{ route('admin.upload.index') }}">Upload Queue</a>.
    </p>

@endsection
