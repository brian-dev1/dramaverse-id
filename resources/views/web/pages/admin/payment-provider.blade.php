@extends('web.layouts.admin')

@section('title', 'Metode Pembayaran')

@section('content')

    <section class="panel">
        <div class="panel-head">
            <h2>Setup cepat Trakteer</h2>
            <span class="panel-meta">Yang penting untuk pembayaran VIP otomatis</span>
        </div>

        <div class="detail-body-admin">
            <ol class="page-subtitle">
                <li>Pastikan provider Trakteer berstatus <strong>Aktif</strong> dan <strong>utama</strong>.</li>
                <li>Salin URL callback Trakteer dari kartu provider di bawah ke dashboard Trakteer.</li>
                <li>Token webhook di Trakteer harus sama dengan field <code>webhook_token</code> di sini.</li>
                <li>Uji setelah bayar dengan command: <code>php artisan payment:diagnose --last</code>.</li>
            </ol>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Metode pembayaran</h2>
            <span class="panel-meta">
                Provider tidak dipatok di kode. Menambah, mengganti, atau mematikan
                metode adalah pekerjaan di halaman ini, bukan pekerjaan deploy.
            </span>
        </div>

        @if ($providers->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Belum ada metode pembayaran. Jalankan di server:
                    <code>php artisan db:seed --class='Database\Seeders\PaymentProviderSeeder' --force</code>
                    untuk memasang Transfer Bank, atau tambahkan sendiri di bawah.
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Driver</th>
                            <th>Mode</th>
                            <th>Biaya</th>
                            <th>Transaksi</th>
                            <th>Callback</th>
                            <th>Status</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providers as $p)
                            <tr>
                                <td>
                                    {{ $p->name }}
                                    <br><span class="cell-empty">{{ $p->slug }}</span>
                                </td>
                                <td>
                                    {{ $p->driver->label() }}
                                    @unless ($p->driver->isImplemented())
                                        <br><span class="badge badge-off">kerangka</span>
                                    @endunless
                                </td>
                                <td>
                                    <span class="badge {{ $p->isSandbox() ? 'badge-pending' : 'badge-on' }}">
                                        {{ $p->mode }}
                                    </span>
                                </td>
                                <td>
                                    {{ rtrim(rtrim(number_format((float) $p->fee_percent, 2, ',', '.'), '0'), ',') }}%
                                    @if ((float) $p->fee_flat > 0)
                                        <br><span class="cell-empty">
                                            + Rp {{ number_format((float) $p->fee_flat, 0, ',', '.') }}
                                        </span>
                                    @endif
                                </td>
                                <td>{{ number_format($p->transactions_count) }}</td>
                                <td>
                                    <code>{{ url('/payment/callback/'.$p->slug) }}</code>
                                    @if ($p->driver->usesUnits())
                                        <br><span class="cell-empty">
                                            Unit opsional; nominal webhook tetap jadi patokan.
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $p->is_active ? 'badge-on' : 'badge-off' }}">
                                        {{ $p->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                    @if ($p->is_default)
                                        <br><span class="badge badge-on">utama</span>
                                    @endif
                                    @if ($alasan = $p->blocker())
                                        <br><span class="queue-error">{{ $alasan }}</span>
                                    @endif
                                </td>
                                <td class="col-actions">
                                    @if ($p->is_active)
                                        <button type="submit" class="btn btn-sm" form="disable-{{ $p->id }}">
                                            Nonaktifkan
                                        </button>
                                    @else
                                        <button type="submit" class="btn btn-sm" form="enable-{{ $p->id }}">
                                            Aktifkan
                                        </button>
                                    @endif

                                    @unless ($p->is_default)
                                        <button type="submit" class="btn btn-sm" form="default-{{ $p->id }}">
                                            Jadikan utama
                                        </button>
                                        <button type="submit" class="btn btn-sm btn-danger" form="hapus-{{ $p->id }}"
                                                onclick="return confirm('Hapus {{ $p->name }}?')">
                                            <x-web.home.icon name="trash" :size="14" />
                                        </button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Form aksi ditutup di luar tabel; lihat catatan form bersarang di STATUS.md. --}}
            @foreach ($providers as $p)
                <form method="POST" action="{{ route('admin.payment-provider.enable', $p->id) }}"
                      id="enable-{{ $p->id }}" class="inline-form">@csrf</form>
                <form method="POST" action="{{ route('admin.payment-provider.disable', $p->id) }}"
                      id="disable-{{ $p->id }}" class="inline-form">@csrf</form>
                <form method="POST" action="{{ route('admin.payment-provider.default', $p->id) }}"
                      id="default-{{ $p->id }}" class="inline-form">@csrf</form>
                <form method="POST" action="{{ route('admin.payment-provider.destroy', $p->id) }}"
                      id="hapus-{{ $p->id }}" class="inline-form">@csrf @method('DELETE')</form>
            @endforeach
        @endif
    </section>

    @foreach ($providers as $p)
        <section class="panel">
            <div class="panel-head">
                <h2>{{ $p->name }}</h2>
                <span class="panel-meta">
                    {{ $p->driver->label() }} · callback:
                    <code>{{ url('/payment/callback/'.$p->slug) }}</code>
                </span>
            </div>

            <form method="POST" action="{{ route('admin.payment-provider.update', $p->id) }}"
                  class="admin-form">
                @csrf
                @method('PUT')

                <x-admin.field name="name" label="Nama" :value="$p->name" required />

                <x-admin.field name="mode" label="Mode" type="select" required :value="$p->mode"
                               :options="['sandbox' => 'Sandbox (uji coba)', 'live' => 'Live (sungguhan)']"
                               hint="Provider sandbox yang tidak sengaja dijadikan utama berarti pembayaran sungguhan tidak pernah masuk." />

                @foreach ($p->driver->credentialFields() as $field => $label)
                    <x-admin.field :name="'credentials['.$field.']'" :label="$label"
                                   :hint="$p->credential($field) ? 'Sudah terisi. Kosongkan untuk membiarkannya.' : 'Belum diisi.'" />
                @endforeach

                <x-admin.field name="fee_percent" label="Biaya layanan (%)" type="number"
                               :value="$p->fee_percent" />

                <x-admin.field name="fee_flat" label="Biaya tetap (Rp)" type="number"
                               :value="$p->fee_flat" />

                <x-admin.field name="instruction" label="Instruksi untuk pengguna" type="textarea"
                               :rows="4" :value="$p->instruction" />

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <x-web.home.icon name="check" :size="14" /> Simpan
                    </button>
                </div>
            </form>
        </section>
    @endforeach

    <section class="panel">
        <div class="panel-head"><h2>Tambah metode</h2></div>

        <form method="POST" action="{{ route('admin.payment-provider.store') }}" class="admin-form">
            @csrf

            <x-admin.field name="name" label="Nama" :value="old('name')" required
                           hint="Yang dilihat pengguna di halaman pembayaran." />

            <x-admin.field name="driver" label="Driver" type="select" required
                           :value="old('driver')" :options="$drivers"
                           hint="Driver bertanda kerangka belum bisa diaktifkan." />

            <x-admin.field name="mode" label="Mode" type="select" required
                           :value="old('mode', 'sandbox')"
                           :options="['sandbox' => 'Sandbox (uji coba)', 'live' => 'Live (sungguhan)']" />

            <x-admin.field name="fee_percent" label="Biaya layanan (%)" type="number" :value="old('fee_percent', 0)" />

            <x-admin.field name="fee_flat" label="Biaya tetap (Rp)" type="number" :value="old('fee_flat', 0)" />

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <x-web.home.icon name="plus" :size="14" /> Tambah
                </button>
            </div>
        </form>
    </section>

@endsection
