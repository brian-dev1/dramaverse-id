@extends('web.layouts.admin')

@section('title', 'Pengaturan')

@section('content')

    <form method="POST" action="{{ route('admin.settings.update') }}"
          enctype="multipart/form-data" class="admin-form">
        @csrf
        @method('PUT')

        <div class="form-grid">
            @foreach ($groups as $key => $groupLabel)
                @continue(empty($schema[$key]))

                <section class="form-card">
                    <h2>{{ $groupLabel }}</h2>

                    @if ($key === 'channel')
                        {{--
                            Pemilih template siap pakai.

                            Diletakkan DI ATAS kolom-kolomnya dengan sengaja:
                            yang membuka bagian ini pertama kali menghadapi
                            kotak teks kosong dan daftar sebelas placeholder.
                            Kartu-kartu ini menjawab "jadi harus diisi apa"
                            sebelum pertanyaannya sempat muncul.

                            Tombolnya hanya mengisi kolom, tidak menyimpan.
                            Penyimpanan tetap satu jalur — tombol Simpan di
                            bawah — supaya tidak ada dua cara menyimpan hal
                            yang sama dengan hasil yang bisa berbeda.
                        --}}
                        <div class="tpl-picker">
                            <p class="tpl-picker-head">
                                Pilih template siap pakai, lalu sunting seperlunya.
                                Kolom di bawah akan terisi otomatis.
                            </p>

                            @foreach ($channelTemplates as $tpl)
                                <div class="tpl-card">
                                    <div class="tpl-card-main">
                                        <strong>{{ $tpl['nama'] }}</strong>
                                        <span>{{ $tpl['ringkas'] }}</span>
                                    </div>

                                    <button type="button" class="btn btn-sm"
                                            data-tpl
                                            data-tpl-nama="{{ $tpl['nama'] }}"
                                            data-tpl-template="{{ $tpl['template'] }}"
                                            data-tpl-baris="{{ $tpl['baris'] }}"
                                            data-tpl-gratis="{{ $tpl['gratis'] }}"
                                            data-tpl-vip="{{ $tpl['vip'] }}">
                                        Pakai ini
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($schema[$key] as $field)
                        @if ($field['type'] === 'image')
                            <x-admin.image-field
                                :name="$field['key']"
                                :label="$field['label']"
                                :current="$values[$field['key']] ?? null"
                                :hint="$field['hint'] ?? 'JPG, PNG, atau WebP. Maksimal 4 MB.'" />
                        @else
                            <x-admin.field
                                :name="$field['key']"
                                :label="$field['label']"
                                :type="$field['type'] === 'boolean' ? 'checkbox' : $field['type']"
                                :value="$values[$field['key']] ?? null"
                                :hint="$field['hint']" />
                        @endif
                    @endforeach
                </section>
            @endforeach
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
        </div>
    </form>

@endsection
