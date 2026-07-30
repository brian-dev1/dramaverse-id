@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid">

            <section class="form-card form-main">
                <h2>Identitas</h2>

                <x-admin.field name="name" label="Nama" :value="$record->name" required
                               placeholder="Cloudflare R2 Produksi" />

                <x-admin.field name="slug" label="Slug" :value="$record->slug"
                               hint="Kunci pemanggilan dari kode, mis. storage()->disk('r2'). Kosongkan untuk dibuat otomatis dari nama." />

                <x-admin.field name="driver" label="Driver" type="select"
                               :value="$record->driver?->value"
                               :options="$driverOptions" required
                               hint="Menentukan kolom mana yang wajib diisi. Lihat tabel di bawah." />

                <x-admin.field name="priority" label="Priority" type="number"
                               :value="$record->priority ?? 100" required
                               min="0" max="65535"
                               hint="Angka lebih kecil dicoba lebih dulu. Penyimpanan lokal ada di 900." />
            </section>

            <section class="form-card">
                <h2>Lokasi</h2>

                <x-admin.field name="bucket" label="Bucket" :value="$record->bucket"
                               hint="Untuk Azure, ini nama container." />

                <x-admin.field name="endpoint" label="Endpoint" :value="$record->endpoint"
                               placeholder="https://xxxx.r2.cloudflarestorage.com"
                               hint="Boleh memuat port, mis. http://127.0.0.1:9000 untuk MinIO." />

                <x-admin.field name="region" label="Region" :value="$record->region"
                               hint="Kosongkan bila provider punya nilai bawaan. R2 selalu memakai auto." />

                <x-admin.field name="root" label="Prefix folder" :value="$record->root"
                               placeholder="video"
                               hint="Opsional. Subfolder di dalam bucket." />

                <x-admin.field name="public_url" label="URL publik" :value="$record->public_url"
                               placeholder="https://cdn.dracinverse.cloud"
                               hint="Domain atau CDN di depan bucket. Untuk R2 dan B2 hampir selalu perlu diisi, karena URL bawaan adapter tidak dapat diakses publik." />
            </section>

            <section class="form-card">
                <h2>Kredensial</h2>

                <p class="field-hint">
                    Disimpan terenkripsi memakai APP_KEY dan tidak pernah
                    ditampilkan kembali. Mengganti APP_KEY membuat kunci ini
                    tidak bisa dibaca lagi.
                </p>

                {{-- value dikosongkan dengan sengaja: kredensial tidak pernah
                     diisi ulang, bahkan setelah validasi gagal. --}}
                <x-admin.field name="access_key" label="Access key" type="password"
                               :value="null" autocomplete="off" />

                <x-admin.field name="secret_key" label="Secret key" type="password"
                               :value="null" autocomplete="off" />
            </section>

            <section class="form-card">
                <h2>Perilaku</h2>

                <x-admin.field name="visibility" label="Visibility" type="select"
                               :value="$record->visibility ?? 'private'"
                               :options="$visibilityOptions" required
                               hint="Privat adalah pilihan aman. Bucket video yang terbuka untuk umum bisa diunduh siapa pun yang menebak URL-nya." />

                <x-admin.field name="use_path_style" label="Path-style endpoint" type="checkbox"
                               :value="$record->use_path_style ?? false"
                               hint="Bucket ditulis sebagai path, bukan subdomain" />

                <p class="field-hint">
                    MinIO dan R2 selalu memakai path-style, dan sistem
                    memaksakannya sendiri untuk kedua provider itu meskipun
                    kotak di atas tidak dicentang.
                </p>
            </section>

            <section class="form-card form-main">
                <h2>Kolom wajib per driver</h2>

                <p class="field-hint">
                    Daftar ini dibangkitkan dari kode yang sama dengan
                    validasinya, jadi tidak bisa berbeda dari yang benar-benar
                    diperiksa server.
                </p>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Driver</th>
                                <th>Kolom wajib</th>
                                <th>Adapter</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requirements as $value => $info)
                                <tr>
                                    <td>{{ $info['label'] }}</td>
                                    <td>
                                        @if (empty($info['required']))
                                            <span class="cell-empty">tidak ada</span>
                                        @else
                                            {{ implode(', ', $info['required']) }}
                                        @endif

                                        @if ($info['region'])
                                            <span class="field-hint">region bawaan: {{ $info['region'] }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($info['ready'])
                                            <span class="badge badge-on">Terpasang</span>
                                        @else
                                            <span class="badge badge-off">{{ $info['package'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="form-card form-main">
                <h2>Setelah disimpan</h2>

                <p class="field-hint">
                    Provider baru selalu tersimpan dengan status
                    <strong>nonaktif</strong>, dan tidak dijadikan default.
                    Itu disengaja: tujuan penyimpanan tidak boleh menerima
                    lalu lintas sebelum terbukti bisa dihubungi.
                </p>

                <p class="field-hint">
                    Uji dulu dari server dengan
                    <code>php artisan storage:test</code>. Tombol Test
                    Connection, Enable, dan Set Default menyusul di sprint
                    berikutnya.
                </p>
            </section>

        </div>

    </x-admin.form>

@endsection
