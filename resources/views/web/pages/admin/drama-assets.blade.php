@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    {{--
        Satu kartu per jenis aset. Seluruh unggah dan hapus berjalan lewat XHR,
        jadi halaman tidak pernah dimuat ulang — mengganti satu poster tidak
        seharusnya membuang keadaan kartu-kartu lain.

        Atribut data-* di bawah adalah kontrak antara halaman ini dan modul
        assetManager() di resources/js/admin.js.
    --}}
    <div class="asset-manager"
         data-asset-manager
         data-store-url="{{ route('admin.drama.asset.store', $drama->id) }}"
         data-delete-url="{{ route('admin.drama.asset.destroy', ['drama' => $drama->id, 'asset' => 0]) }}">

        <section class="asset-head">
            <div>
                <h2>{{ $drama->title }}</h2>
                <p class="page-subtitle">
                    Semua aset di bawah disimpan lewat Storage Engine, bukan ke disk lokal.
                </p>
            </div>

            <a href="{{ route('admin.drama.index') }}" class="btn btn-ghost btn-sm">
                <x-web.home.icon name="arrow-left" :size="14" />
                Kembali ke daftar drama
            </a>
        </section>

        {{-- Pilihan penyimpanan berlaku untuk semua kartu di halaman ini. --}}
        <section class="form-card asset-storage">
            <h2>Penyimpanan</h2>

            <div class="field">
                <label>Storage Mode</label>

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
                <label for="asset-provider">Storage Provider</label>

                <select id="asset-provider" class="control" data-provider>
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
                        Hanya provider aktif yang sudah lolos Test Connection.
                    @endif
                </p>
            </div>
        </section>

        <div class="asset-grid">
            @foreach ($types as $type)
                @php
                    $items = $assets[$type->value] ?? collect();
                    $multi = $type->allowsMultiple();
                @endphp

                <section class="form-card asset-card {{ $multi ? 'asset-card-wide' : '' }}"
                         data-asset-card
                         data-type="{{ $type->value }}"
                         data-multiple="{{ $multi ? '1' : '' }}"
                         data-max-kb="{{ $type->maxKb() }}">

                    <h2>
                        <x-web.home.icon :name="$type->icon()" :size="15" />
                        {{ $type->label() }}
                        @if ($multi)
                            <span class="asset-count" data-count>{{ $items->count() }}</span>
                        @endif
                    </h2>

                    <p class="field-hint">{{ $type->description() }}</p>

                    <p class="field-hint">
                        {{ Str::upper(implode(', ', $type->extensions())) }} —
                        maksimal {{ number_format($type->maxKb() / 1024, 1) }} MB
                        @if ($multi) — maksimal 20 berkas sekali unggah @endif
                    </p>

                    {{-- Daftar aset yang sudah ada --}}
                    <div class="asset-items {{ $multi ? 'asset-items-grid' : '' }}" data-items>
                        @forelse ($items as $asset)
                            <article class="asset-item" data-item data-id="{{ $asset->id }}">

                                <div class="asset-thumb">
                                    @if ($asset->isPreviewable())
                                        <img src="{{ $asset->public_url }}"
                                             alt="{{ $asset->original_filename }}" loading="lazy">
                                    @else
                                        <span class="asset-thumb-empty">
                                            <x-web.home.icon :name="$type->icon()" :size="18" />
                                        </span>
                                    @endif
                                </div>

                                <div class="asset-meta">
                                    <p class="asset-name" title="{{ $asset->original_filename }}">
                                        {{ $asset->original_filename }}
                                    </p>
                                    <p class="asset-sub">
                                        {{ $asset->size_for_humans }} —
                                        {{ Str::upper($asset->extension ?? '?') }} —
                                        {{ $asset->provider?->name ?? 'provider terhapus' }}
                                    </p>
                                    <p class="asset-sub">
                                        checksum {{ $asset->checksum_short }}…
                                        @unless ($asset->isReachable())
                                            — <span class="asset-warn">provider tidak bisa dijangkau</span>
                                        @endunless
                                    </p>
                                </div>

                                <button type="button" class="btn-icon btn-danger"
                                        data-delete title="Hapus" aria-label="Hapus aset">
                                    <x-web.home.icon name="trash" :size="15" />
                                </button>
                            </article>
                        @empty
                            <p class="asset-empty" data-empty>Belum ada berkas.</p>
                        @endforelse
                    </div>

                    {{-- Kotak seret-lepas --}}
                    <div class="upload upload-asset" data-drop>
                        <div class="upload-body">
                            <input type="file" class="upload-input" data-file
                                   id="asset-file-{{ $type->value }}"
                                   accept="{{ collect($type->extensions())->map(fn ($e) => '.'.$e)->implode(',') }}"
                                   @if ($multi) multiple @endif>

                            <label for="asset-file-{{ $type->value }}" class="btn btn-ghost btn-sm">
                                <x-web.home.icon name="plus" :size="14" />
                                {{ $items->isNotEmpty() && ! $multi ? 'Ganti berkas' : 'Pilih berkas' }}
                            </label>

                            <p class="upload-name" data-file-name>
                                Seret ke sini, atau pilih lewat tombol.
                            </p>
                        </div>
                    </div>

                    {{-- Pratayang sebelum unggah --}}
                    <div class="asset-preview" data-preview hidden></div>

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
                        <button type="button" class="btn btn-primary btn-sm" data-submit disabled>
                            {{ $items->isNotEmpty() && ! $multi ? 'Ganti' : 'Unggah' }}
                        </button>
                    </div>

                </section>
            @endforeach
        </div>
    </div>

@endsection
