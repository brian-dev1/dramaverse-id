@extends('web.layouts.admin')

@section('title', 'Program Affiliate')

@section('content')

<div class="ref-admin">

    <div class="stat-row">
        <x-admin.stat-card label="Total komisi"       :value="$statistik['komisi_total']"    icon="card"  money />
        <x-admin.stat-card label="Saldo tersedia"     :value="$statistik['komisi_tersedia']" icon="chart" money />
        <x-admin.stat-card label="Sudah dibayarkan"   :value="$statistik['komisi_dibayar']"  icon="check" money />
        <x-admin.stat-card label="Menunggu penarikan" :value="$statistik['menunggu_tarik']"  icon="clock" />
        <x-admin.stat-card label="Total referral"     :value="$statistik['total_referral']"  icon="users" />
        <x-admin.stat-card label="Transaksi referral" :value="$statistik['transaksi']"       icon="file" />
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================ Pengaturan ============================ --}}
    <section class="panel">
        <div class="panel-head"><h2>Pengaturan</h2></div>

        <div class="detail-body-admin">
            <p>
                Komisi dihitung <strong>hanya dari tagihan yang benar-benar lunas</strong>,
                satu komisi per tagihan — dijamin unique index di basis data, bukan oleh
                pemeriksaan di kode. Callback pembayaran yang datang berkali-kali tetap
                menghasilkan satu komisi. Saldo pengguna tidak disimpan sebagai kolom
                yang bisa ditambah; saldo selalu dihitung ulang dari komisi dikurangi
                penarikan, sehingga tidak ada nilai yang bisa digelembungkan.
            </p>

            <form method="POST" action="{{ route('admin.referral.settings') }}" class="form-grid">
                @csrf
                @method('PUT')

                <label class="field">
                    <span>Program aktif</span>
                    <input type="checkbox" name="referral_enabled" value="1"
                           @checked(($config['referral_enabled'] ?? '1') === '1')>
                </label>

                <label class="field">
                    <span>Minimal penarikan (Rp)</span>
                    <input type="number" name="referral_min_withdraw" min="0" step="1000"
                           value="{{ old('referral_min_withdraw', $config['referral_min_withdraw']) }}" required>
                </label>

                <label class="field">
                    <span>Biaya penarikan (%)</span>
                    <input type="number" name="referral_fee_percent" min="0" max="100" step="0.5"
                           value="{{ old('referral_fee_percent', $config['referral_fee_percent']) }}" required>
                </label>

                <label class="field">
                    <span>Masa berlaku tautan (hari)</span>
                    <input type="number" name="referral_cookie_days" min="1" max="365"
                           value="{{ old('referral_cookie_days', $config['referral_cookie_days']) }}" required>
                </label>

                <label class="field">
                    <span>Masa tahan komisi (hari)</span>
                    <input type="number" name="referral_hold_days" min="0" max="90"
                           value="{{ old('referral_hold_days', $config['referral_hold_days']) }}" required>
                    <small>0 berarti komisi langsung bisa ditarik. Isi 3–7 bila ingin ada jeda untuk refund.</small>
                </label>

                <label class="field">
                    <span>Dasar perhitungan</span>
                    <select name="referral_base">
                        <option value="subtotal" @selected($config['referral_base'] === 'subtotal')>Subtotal (tanpa biaya admin)</option>
                        <option value="total"    @selected($config['referral_base'] === 'total')>Total tagihan</option>
                    </select>
                </label>

                <label class="field field-wide">
                    <span>Daftar e-wallet (pisahkan dengan koma)</span>
                    <input type="text" name="referral_ewallets"
                           value="{{ old('referral_ewallets', $config['referral_ewallets']) }}" required>
                </label>

                <div class="field-wide">
                    <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
                </div>
            </form>
        </div>
    </section>

    {{-- ========================= Tingkatan komisi ========================= --}}
    <section class="panel">
        <div class="panel-head"><h2>Tingkatan komisi</h2></div>

        <div class="detail-body-admin">
            <p>
                Rate yang dipakai adalah tingkatan tertinggi yang ambang minimalnya
                sudah dilewati jumlah referral orang tersebut. Rate disalin ke setiap
                komisi saat komisi dibuat, jadi mengubah tabel ini tidak mengubah
                komisi yang sudah terlanjur tercatat.
            </p>

            <form method="POST" action="{{ route('admin.referral.tiers') }}">
                @csrf
                @method('PUT')

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Level</th><th>Rate (%)</th><th>Min. referral</th><th>Aktif</th></tr>
                        </thead>
                        <tbody id="tier-rows">
                            @foreach ($tiers as $i => $tier)
                                <tr>
                                    <td><input type="number" name="tiers[{{ $i }}][level]" value="{{ $tier->level }}" min="1" max="20" required></td>
                                    <td><input type="number" name="tiers[{{ $i }}][rate]" value="{{ (float) $tier->rate }}" min="0" max="100" step="0.5" required></td>
                                    <td><input type="number" name="tiers[{{ $i }}][min_referrals]" value="{{ $tier->min_referrals }}" min="0" required></td>
                                    <td><input type="checkbox" name="tiers[{{ $i }}][is_active]" value="1" @checked($tier->is_active)></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="action-row">
                    <button type="button" class="btn btn-ghost" id="tier-add">Tambah baris</button>
                    <button type="submit" class="btn btn-primary">Simpan tingkatan</button>
                </div>
            </form>
        </div>
    </section>

    {{-- ========================== Komisi khusus ========================== --}}
    <section class="panel">
        <div class="panel-head"><h2>Komisi khusus per orang</h2></div>

        <div class="detail-body-admin">
            <p>
                Tingkatan di atas tetap berjalan seperti biasa untuk semua orang.
                Panel ini hanya untuk <strong>pengecualian</strong>: orang yang persennya
                ditetapkan sendiri dan tidak ikut berubah saat jumlah undangannya naik
                atau saat tabel tingkatan disunting.
            </p>
            <p>
                Kotak persen yang <strong>dikosongkan</strong> berarti orang itu kembali ikut
                tingkatan otomatis. Isi <strong>0</strong> bukan hal yang sama — itu berarti
                tidak dapat komisi sama sekali. Perubahannya berlaku untuk transaksi
                <strong>berikutnya</strong>; komisi yang sudah tercatat tidak ikut berubah,
                karena persennya disalin ke tiap baris komisi saat dibuat.
            </p>
        </div>

        {{-- Cari orangnya dulu. Daftar seluruh pengguna tidak disediakan:
             tabelnya tumbuh terus, dan yang dicari selalu satu orang yang
             namanya sudah diketahui. --}}
        <form method="GET" class="filter-bar">
            {{-- Filter tab lain (cari komisi, status penarikan) dibawa serta
                 supaya mencari pengguna tidak mengosongkan filter di panel
                 lain pada halaman yang sama. --}}
            @foreach (request()->except(['quser', 'page', 'wpage']) as $k => $v)
                @continue (! is_scalar($v))
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach

            <input type="text" name="quser" value="{{ $qUser }}"
                   placeholder="Cari nama, @username, email, atau kode referral...">
            <button type="submit" class="btn btn-ghost">Cari pengguna</button>
        </form>

        @if (strlen($qUser) >= 2)
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Pengguna</th><th>Kode</th><th>Rate sekarang</th><th>Set rate khusus</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($cariUser as $u)
                        <tr>
                            <td>{{ $u->telegram_username ? '@'.$u->telegram_username : $u->name }}</td>
                            <td><code>{{ $u->referral_code ?: '—' }}</code></td>
                            <td>
                                @if ($u->referral_rate_override !== null)
                                    <strong>{{ rtrim(rtrim(number_format((float) $u->referral_rate_override, 2, ',', '.'), '0'), ',') }}%</strong>
                                    <small>khusus</small>
                                @else
                                    <small>ikut tingkatan otomatis</small>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.referral.rate.custom') }}" class="inline-form">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="user_id" value="{{ $u->id }}">
                                    <input type="number" name="rate" min="0" max="100" step="0.5"
                                           style="width:90px"
                                           value="{{ $u->referral_rate_override !== null ? (float) $u->referral_rate_override : '' }}"
                                           placeholder="%">
                                    <input type="text" name="note" maxlength="255" placeholder="Alasan (mis. mitra dekat)">
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Tidak ada pengguna yang cocok dengan "{{ $qUser }}".</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Yang sedang berlaku. Tanpa daftar ini, pengecualian yang dibuat
             berbulan-bulan lalu hanya bisa ditemukan bila ada yang ingat
             namanya. --}}
        <div class="detail-body-admin">
            <h3>Sedang berlaku ({{ $rateKhusus->count() }})</h3>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Pengguna</th><th>Rate khusus</th><th>Undangan</th><th>Alasan</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                @forelse ($rateKhusus as $u)
                    <tr>
                        <td>{{ $u->telegram_username ? '@'.$u->telegram_username : $u->name }}</td>
                        <td><strong>{{ rtrim(rtrim(number_format((float) $u->referral_rate_override, 2, ',', '.'), '0'), ',') }}%</strong></td>
                        <td>{{ number_format($u->total_referral, 0, ',', '.') }}</td>
                        <td>{{ $u->referral_rate_note ?: '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.referral.rate.custom') }}" class="inline-form">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="user_id" value="{{ $u->id }}">
                                <input type="number" name="rate" min="0" max="100" step="0.5"
                                       style="width:90px"
                                       value="{{ (float) $u->referral_rate_override }}">

                                {{-- Alasannya ikut dikirim. Tanpa ini, menaikkan
                                     rate dari 50 ke 60 akan menghapus catatan
                                     kenapa orang ini punya rate khusus. --}}
                                <input type="text" name="note" maxlength="255"
                                       value="{{ $u->referral_rate_note }}" placeholder="Alasan">

                                <button type="submit" class="btn btn-sm btn-primary">Ubah</button>
                            </form>

                            {{-- Mencabut = mengirim kotak rate yang kosong. Tombolnya
                                 dipisah supaya tidak perlu menghapus isian dulu. --}}
                            <form method="POST" action="{{ route('admin.referral.rate.custom') }}" class="inline-form">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="user_id" value="{{ $u->id }}">
                                <button type="submit" class="btn btn-sm btn-ghost">Cabut</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Belum ada yang pakai rate khusus. Semua ikut tingkatan otomatis.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ======================= Penarikan menunggu ======================== --}}
    <section class="panel">
        <div class="panel-head"><h2>Penarikan saldo</h2></div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Pengguna</th><th>Jumlah</th><th>Tujuan</th>
                        <th>Status</th><th>Waktu</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($penarikan as $w)
                    <tr>
                        <td>{{ $w->user?->telegram_username ? '@'.$w->user->telegram_username : $w->user?->name }}</td>
                        <td>
                            Rp {{ number_format($w->amount, 0, ',', '.') }}
                            @if ($w->fee > 0)
                                <small>(bersih Rp {{ number_format($w->net_amount, 0, ',', '.') }})</small>
                            @endif
                        </td>
                        <td>{{ $w->method }} &middot; {{ $w->account_number }}<br><small>{{ $w->account_name }}</small></td>
                        <td><span class="badge">{{ $w->status }}</span></td>
                        <td>{{ \App\Support\Waktu::ringkas($w->created_at) }}</td>
                        <td>
                            @if ($w->status !== 'paid')
                                <form method="POST" action="{{ route('admin.referral.withdrawal.process', $w->id) }}" class="inline-form">
                                    @csrf
                                    <select name="action" required>
                                        <option value="approved">Setujui</option>
                                        <option value="paid">Tandai dibayar</option>
                                        <option value="rejected">Tolak</option>
                                    </select>
                                    <input type="text" name="note" placeholder="Catatan" maxlength="255">
                                    <button type="submit" class="btn btn-sm btn-primary">Proses</button>
                                </form>
                            @else
                                <small>Selesai {{ $w->processed_at?->diffForHumans() }}</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Belum ada permintaan penarikan.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $penarikan->links() }}
    </section>

    {{-- ============================= Komisi ============================== --}}
    <section class="panel">
        <div class="panel-head"><h2>Daftar komisi</h2></div>

        <form method="GET" class="filter-bar">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari pengundang...">
            <select name="status">
                <option value="">Semua status</option>
                @foreach (['available' => 'Tersedia', 'pending' => 'Ditahan', 'paid' => 'Dibayar', 'void' => 'Dibatalkan'] as $k => $v)
                    <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-ghost">Filter</button>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Pengundang</th><th>Pembeli</th><th>Tagihan</th>
                        <th>Dasar</th><th>Rate</th><th>Komisi</th>
                        <th>Status</th><th>Waktu</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($komisi as $k)
                    <tr>
                        <td>{{ $k->referrer?->telegram_username ? '@'.$k->referrer->telegram_username : $k->referrer?->name }}</td>
                        <td>{{ $k->referredUser?->telegram_username ? '@'.$k->referredUser->telegram_username : $k->referredUser?->name }}</td>
                        <td>{{ $k->invoice?->number }}</td>
                        <td>Rp {{ number_format($k->base_amount, 0, ',', '.') }}</td>
                        <td>{{ (float) $k->rate }}%</td>
                        <td><strong>Rp {{ number_format($k->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="badge">{{ $k->status }}</span></td>
                        <td>{{ \App\Support\Waktu::ringkas($k->created_at) }}</td>
                        <td>
                            @if ($k->status === 'void')
                                <form method="POST" action="{{ route('admin.referral.commission.restore', $k->id) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-ghost">Pulihkan</button>
                                </form>
                            @elseif ($k->status !== 'paid')
                                <form method="POST" action="{{ route('admin.referral.commission.void', $k->id) }}" class="inline-form">
                                    @csrf
                                    <input type="text" name="note" placeholder="Alasan" maxlength="255">
                                    <button type="submit" class="btn btn-sm btn-danger">Batalkan</button>
                                </form>
                            @else
                                <small>Terkunci</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">Belum ada komisi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $komisi->links() }}
    </section>

    {{-- ========================= Papan peringkat ========================= --}}
    <section class="panel">
        <div class="panel-head"><h2>Peringkat affiliate</h2></div>

        <div class="detail-body-admin">
            <p>
                Kolom <strong>Undangan</strong> dibandingkan dengan <strong>Transaksi</strong>
                adalah alat deteksi kecurangan yang paling sederhana: banyak undangan
                dengan nol transaksi biasanya berarti akun kembar, bukan pemasaran.
            </p>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>#</th><th>Pengguna</th><th>Kode</th><th>Undangan</th><th>Transaksi</th><th>Komisi</th></tr>
                </thead>
                <tbody>
                @forelse ($peringkat as $i => $p)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $p->telegram_username ? '@'.$p->telegram_username : $p->name }}</td>
                        <td><code>{{ $p->referral_code }}</code></td>
                        <td>{{ $p->total_referral }}</td>
                        <td>{{ $p->total_transaksi }}</td>
                        <td>Rp {{ number_format($p->total_komisi, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Belum ada affiliate aktif.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        // Tambah baris tingkatan. Indeks diambil dari jumlah baris yang ada
        // supaya nama input tidak bentrok dengan baris yang sudah dirender.
        document.getElementById('tier-add')?.addEventListener('click', function () {
            var tbody = document.getElementById('tier-rows');
            var i = tbody.rows.length;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="number" name="tiers[' + i + '][level]" value="' + (i + 1) + '" min="1" max="20" required></td>' +
                '<td><input type="number" name="tiers[' + i + '][rate]" value="0" min="0" max="100" step="0.5" required></td>' +
                '<td><input type="number" name="tiers[' + i + '][min_referrals]" value="0" min="0" required></td>' +
                '<td><input type="checkbox" name="tiers[' + i + '][is_active]" value="1" checked></td>';
            tbody.appendChild(tr);
        });
    </script>

</div>

@endsection
