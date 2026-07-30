@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    {{--
        Formulir ini dikirim lewat XHR, bukan submit biasa, supaya progress bar
        punya sesuatu untuk dibaca. Atribut data-* di bawah adalah kontrak
        antara halaman ini dan modul videoUpload() di resources/js/admin.js.

        Bila JavaScript mati, tombolnya tetap mengirim formulir secara normal
        ke route yang sama — respons JSON-nya tidak enak dilihat, tetapi
        berkasnya tetap terunggah dan tidak ada yang hilang.
    --}}
    <form method="POST" action="{{ route('admin.episode.video.store') }}"
          class="admin-form" enctype="multipart/form-data"
          data-video-upload
          data-episodes-url="{{ route('admin.episode.video.episodes', ['drama' => 0]) }}"
          data-max-kb="{{ $maxKb }}">
        @csrf

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Episode tujuan</h2>

                <x-admin.field name="drama_id" label="Drama" type="select" required
                               :value="old('drama_id', $selected)"
                               :options="$dramas->pluck('title', 'id')->all()"
                               data-drama
                               hint="Pilih drama dulu — daftar episodenya menyusul." />

                <div class="field">
                    <label for="field-episode_id">
                        Episode
                        <span class="field-required" aria-hidden="true">*</span>
                    </label>

                    <select id="field-episode_id" name="episode_id" class="control"
                            data-episode required disabled>
                        <option value="">— pilih drama dulu —</option>
                    </select>

                    <p class="field-hint" data-episode-note>
                        Episode dibuat lewat menu Episode. Halaman ini hanya
                        mengunggah videonya.
                    </p>

                    @error('episode_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <x-admin.field name="title" label="Judul Episode" :value="old('title')"
                               data-title
                               hint="Opsional. Bila diisi, judul episode ikut diperbarui." />

                {{--
                    Season sengaja TIDAK ada di formulir ini.

                    Spesifikasi sprint menuliskannya sebagai "Season (jika ada)",
                    dan di DramaVerse ID konsep itu memang belum ada — tidak ada
                    kolom, tabel, maupun relasi season di seluruh proyek.
                    Menambahkan pilihan yang tidak menyimpan ke mana pun hanya
                    akan tampak berfungsi.
                --}}
            </section>

            <section class="form-card">
                <h2>Penyimpanan</h2>

                <div class="field">
                    <label>Storage Mode <span class="field-required" aria-hidden="true">*</span></label>

                    <div class="radio-row">
                        <label class="checkbox-item">
                            <input type="radio" name="storage_mode" value="auto"
                                   data-mode @checked(old('storage_mode', 'auto') === 'auto')>
                            Auto
                        </label>

                        <label class="checkbox-item">
                            <input type="radio" name="storage_mode" value="manual"
                                   data-mode @checked(old('storage_mode') === 'manual')>
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

                    @error('storage_mode')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field" data-provider-wrap hidden>
                    <label for="field-storage_provider_id">
                        Storage Provider
                        <span class="field-required" aria-hidden="true">*</span>
                    </label>

                    <select id="field-storage_provider_id" name="storage_provider_id"
                            class="control" data-provider>
                        <option value="">— pilih provider —</option>
                        @foreach ($providers as $id => $label)
                            <option value="{{ $id }}"
                                    @selected((string) old('storage_provider_id') === (string) $id)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>

                    <p class="field-hint">
                        @if ($providers === [])
                            Belum ada provider yang memenuhi syarat. Provider harus aktif,
                            lengkap, dan sudah lolos Test Connection.
                        @else
                            Hanya provider aktif yang sudah lolos Test Connection yang
                            terdaftar di sini.
                        @endif
                    </p>

                    @error('storage_provider_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="form-card form-main">
                <h2>Berkas video</h2>

                <div class="upload upload-video {{ $errors->has('video') ? 'field-invalid' : '' }}"
                     data-drop>

                    <div class="upload-body">
                        <input type="file" name="video" id="field-video"
                               class="upload-input" data-file
                               accept="{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}"
                               required>

                        <label for="field-video" class="btn btn-primary btn-sm">
                            <x-web.home.icon name="plus" :size="14" />
                            Pilih berkas video
                        </label>

                        <p class="upload-name" data-file-name>
                            Seret berkas ke sini, atau pilih lewat tombol di atas.
                        </p>

                        <p class="field-hint">
                            {{ Str::upper(implode(', ', $extensions)) }}.
                            Maksimal {{ number_format($maxKb / 1024, 0) }} MB.
                            Tersimpan sebagai privat di <code>{{ $directory }}</code>.
                        </p>
                    </div>
                </div>

                @error('video')<p class="field-error">{{ $message }}</p>@enderror

                {{-- Pratayang informasi berkas, diisi JS setelah berkas dipilih. --}}
                <dl class="file-facts" data-facts hidden>
                    <div><dt>Nama</dt><dd data-fact-name>—</dd></div>
                    <div><dt>Ukuran</dt><dd data-fact-size>—</dd></div>
                    <div><dt>Format</dt><dd data-fact-type>—</dd></div>
                    <div><dt>Tujuan</dt><dd data-fact-target>—</dd></div>
                </dl>

                {{-- Progress bar. aria-* diperbarui JS supaya pembaca layar ikut tahu. --}}
                <div class="progress" data-progress hidden>
                    <div class="progress-track">
                        <div class="progress-bar" data-progress-bar
                             role="progressbar" aria-valuemin="0" aria-valuemax="100"
                             aria-valuenow="0" style="width:0%"></div>
                    </div>
                    <p class="progress-label" data-progress-label>Menyiapkan…</p>
                </div>

                <div class="upload-result" data-result hidden></div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" data-submit>
                        Unggah video
                    </button>

                    <a href="{{ route('admin.episode.index') }}" class="btn btn-ghost">
                        Kembali ke daftar episode
                    </a>
                </div>
            </section>

        </div>
    </form>

@endsection
