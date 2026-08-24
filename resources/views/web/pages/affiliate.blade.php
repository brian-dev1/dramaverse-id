@extends('web.layouts.app')

@section('title', 'Program Affiliate')

@section('content')

    <section class="section section-pad">
        <a href="{{ route('web.profile') }}" class="af-back">
            <x-web.home.icon name="arrow-left" :size="16" /> Kembali
        </a>
    </section>

    @if (session('status'))
        <section class="section section-pad"><div class="af-flash ok">{{ session('status') }}</div></section>
    @endif
    @if (session('error'))
        <section class="section section-pad"><div class="af-flash bad">{{ session('error') }}</div></section>
    @endif

    {{--
        Seluruh angka di halaman ini diberi atribut data-af agar skrip di
        bawah bisa memperbaruinya tanpa memuat ulang halaman. Nilai awal
        tetap dirender server: halaman harus benar walau JavaScript mati.
    --}}
    <section class="section section-pad" data-affiliate
             data-stats-url="{{ route('web.affiliate.stats') }}"
             data-range="{{ $range }}">

        <x-web.home.section-header title="Program Affiliate" />

        <div class="af-card">
            <div class="af-grid">
                <div class="af-stat">
                    <span class="af-stat-k"><span class="af-stat-ico green"><x-web.home.icon name="wallet" :size="13" /></span>Komisi</span>
                    <span class="af-stat-v green" data-af="commission">Rp {{ number_format($summary['commission'], 0, ',', '.') }}</span>
                </div>
                <div class="af-stat">
                    <span class="af-stat-k"><span class="af-stat-ico yellow"><x-web.home.icon name="trend" :size="13" /></span>Rate</span>
                    <span class="af-stat-v yellow" data-af="rate">{{ rtrim(rtrim(number_format($summary['rate'], 2, ',', '.'), '0'), ',') }}%</span>
                </div>
                <div class="af-stat">
                    <span class="af-stat-k"><span class="af-stat-ico blue"><x-web.home.icon name="card" :size="13" /></span>Transaksi Referral</span>
                    <span class="af-stat-v blue" data-af="transactions">{{ $summary['transactions'] }}</span>
                </div>
                <div class="af-stat">
                    <span class="af-stat-k"><span class="af-stat-ico violet"><x-web.home.icon name="users" :size="13" /></span>Total Referral</span>
                    <span class="af-stat-v violet" data-af="total_referrals">{{ $summary['total_referrals'] }}</span>
                </div>
            </div>

            <div class="af-box">
                <span class="af-box-k">Link Referral</span>
                <div class="af-copy">
                    <input type="text" readonly value="{{ $summary['link'] }}" class="af-input" id="af-link">
                    <button type="button" class="af-copy-btn" data-copy="{{ $summary['link'] }}"><x-web.home.icon name="copy" :size="13" /> Salin</button>
                </div>
                @if (! empty($summary['channel']))
                    <a href="{{ $summary['channel'] }}" target="_blank" rel="noopener" class="af-ghost-btn">
                        <x-web.home.icon name="send" :size="13" /> Buka Channel DramaVerse
                    </a>
                @endif

                <p class="af-hint">
                    Tautan ini membuka bot DramaVerse. Orang yang menekan Mulai di sana langsung
                    tercatat sebagai referral Anda, dan komisi masuk otomatis begitu ia berlangganan.
                </p>
            </div>

            <div class="af-box">
                <span class="af-box-k">Statistik Level Referral</span>
                <ul class="af-tiers">
                    {{-- Dengan rate khusus, tidak ada baris yang disorot: persen
                         yang berlaku bagi orang ini memang tidak ada di tangga
                         ini, dan menyorot salah satunya hanya akan membuat
                         angka di kartu atas terlihat seperti salah hitung. --}}
                    @foreach ($tiers as $tier)
                        <li class="{{ ! ($summary['custom_rate'] ?? false) && $tier->level === $summary['level'] ? 'now' : '' }}">
                            <span>Level {{ $tier->level }} - {{ rtrim(rtrim(number_format($tier->rate, 2, ',', '.'), '0'), ',') }}%</span>
                            <span class="af-tier-min">Min {{ number_format($tier->min_referrals, 0, ',', '.') }} referral</span>
                        </li>
                    @endforeach
                </ul>
                <p class="af-level-now">
                    Level saat ini: <strong data-af="level">{{ $summary['level'] }}</strong>
                    (<span data-af="rate2">{{ rtrim(rtrim(number_format($summary['rate'], 2, ',', '.'), '0'), ',') }}%</span>)
                </p>

                @if ($summary['custom_rate'] ?? false)
                    <p class="af-hint">
                        Persentase komisi Anda ditetapkan khusus, jadi tidak mengikuti
                        tangga di atas dan tidak berubah saat jumlah undangan bertambah.
                    </p>
                @endif
            </div>
        </div>
    </section>

    {{-- Grafik --}}
    <section class="section section-pad">
        <x-web.home.section-header title="Statistik Referral" />

        <div class="af-card">
            <div class="af-range">
                @foreach ([7 => '7 Hari', 30 => '30 Hari', 90 => '90 Hari'] as $nilai => $label)
                    <a href="{{ route('web.affiliate', ['range' => $nilai]) }}"
                       class="af-range-btn {{ $range === $nilai ? 'on' : '' }}">{{ $label }}</a>
                @endforeach
            </div>

            <div class="af-legend">
                <span><i class="dot orange"></i> Komisi (Rp)</span>
                <span><i class="dot green"></i> Transaksi</span>
            </div>

            {{--
                Grafik digambar sebagai SVG langsung oleh skrip di bawah.
                Tidak memuat pustaka chart apa pun: dua deret garis tidak
                sepadan dengan 200 KB unduhan tambahan di ponsel.
            --}}
            <div class="af-chart" id="af-chart" data-series='@json($series)'></div>

            <div class="af-foot">
                <div><span>Total Komisi</span><strong data-af="commission2">Rp {{ number_format($summary['commission'], 0, ',', '.') }}</strong></div>
                <div><span>Total Transaksi</span><strong data-af="transactions2">{{ $summary['transactions'] }}</strong></div>
                <div><span>Periode</span><strong>{{ $range }} hari</strong></div>
            </div>
        </div>
    </section>

    {{-- Tarik saldo --}}
    <section class="section section-pad">
        <x-web.home.section-header title="Tarik Saldo" />

        <div class="af-card">
            <div class="af-balance">
                <span>Saldo Tersedia</span>
                <strong data-af="balance">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</strong>
            </div>

            <p class="af-minmax">
                <span>Min. Penarikan: Rp {{ number_format($summary['min_withdraw'], 0, ',', '.') }}</span>
                <span>Biaya: {{ rtrim(rtrim(number_format($summary['fee_percent'], 2, ',', '.'), '0'), ',') }}%</span>
            </p>

            <form method="POST" action="{{ route('web.affiliate.withdraw') }}" class="af-form" data-tarik-form>
                @csrf

                {{--
                    Jumlahnya dipilih sendiri, tidak lagi selalu seluruh saldo.
                    `min` dan `max` di sini cuma bantuan mengetik; yang mengikat
                    adalah pemeriksaan di dalam transaksi ReferralService, yang
                    berjalan saat baris penggunanya terkunci.
                --}}
                <div style="display:flex;gap:8px;align-items:stretch">
                    <input type="number" name="amount" class="af-field" style="flex:1 1 auto"
                           placeholder="Jumlah penarikan (Rp)"
                           value="{{ old('amount') }}"
                           min="{{ (int) $summary['min_withdraw'] }}"
                           max="{{ $summary['balance'] }}"
                           step="0.01" inputmode="numeric" required
                           data-tarik-jumlah
                           data-saldo="{{ $summary['balance'] }}"
                           data-min="{{ $summary['min_withdraw'] }}"
                           data-fee="{{ $summary['fee_percent'] }}">

                    <button type="button" class="af-field" style="flex:0 0 auto;width:auto;cursor:pointer" data-tarik-semua>Semua</button>
                </div>

                <p class="af-row-sub" data-tarik-info></p>

                <select name="method" class="af-field" required>
                    <option value="">Pilih E-Wallet</option>
                    @foreach ($ewallets as $ew)
                        <option value="{{ $ew }}" @selected(old('method') === $ew)>{{ $ew }}</option>
                    @endforeach
                </select>

                <input type="text" name="account_number" class="af-field" placeholder="Nomor E-Wallet"
                       value="{{ old('account_number') }}" required inputmode="numeric">

                <input type="text" name="account_name" class="af-field" placeholder="Nama Pemilik Akun"
                       value="{{ old('account_name') }}" required>

                @error('amount') <p class="af-err">{{ $message }}</p> @enderror
                @error('method') <p class="af-err">{{ $message }}</p> @enderror
                @error('account_number') <p class="af-err">{{ $message }}</p> @enderror
                @error('account_name') <p class="af-err">{{ $message }}</p> @enderror

                <button type="submit" class="af-submit"
                        @disabled($summary['balance'] < $summary['min_withdraw'])>
                    Ajukan Penarikan
                </button>

                @if ($summary['balance'] < $summary['min_withdraw'])
                    <p class="af-row-sub">
                        Saldo belum mencapai minimal penarikan
                        Rp {{ number_format($summary['min_withdraw'], 0, ',', '.') }}.
                    </p>
                @endif
            </form>
        </div>
    </section>

    {{-- Riwayat penarikan --}}
    <section class="section section-pad">
        <x-web.home.section-header title="Riwayat Penarikan" />

        <div class="af-card">
            @forelse ($withdrawals as $w)
                <div class="af-row">
                    <div>
                        <strong>Rp {{ number_format($w->amount, 0, ',', '.') }}</strong>
                        <span class="af-row-sub">{{ $w->method }} &middot; {{ $w->account_number }}</span>
                        @if ($w->fee > 0)
                            <span class="af-row-sub">
                                Potongan Rp {{ number_format($w->fee, 0, ',', '.') }} &middot;
                                diterima Rp {{ number_format($w->net_amount, 0, ',', '.') }}
                            </span>
                        @endif
                    </div>
                    <div class="af-row-right">
                        <span class="af-pill s-{{ $w->status }}">
                            {{ ['pending' => 'Menunggu', 'approved' => 'Diproses', 'paid' => 'Dibayar', 'rejected' => 'Ditolak'][$w->status] ?? $w->status }}
                        </span>
                        <span class="af-row-sub">{{ \App\Support\Waktu::ringkas($w->created_at) }}</span>
                    </div>
                </div>
            @empty
                <p class="af-empty">Belum ada riwayat penarikan.</p>
            @endforelse
        </div>
    </section>

    {{-- Daftar orang yang diundang: bukti transparan, tidak bisa dikarang --}}
    <section class="section section-pad">
        <x-web.home.section-header title="Referral Masuk" />

        <div class="af-card">
            @forelse ($invited as $orang)
                <div class="af-row">
                    <div>
                        <strong>{{ $orang->telegram_username ? '@'.$orang->telegram_username : $orang->name }}</strong>
                        <span class="af-row-sub">Bergabung {{ $orang->referred_at ? \App\Support\Waktu::ringkas($orang->referred_at) : '-' }}</span>
                    </div>
                    <span class="af-pill {{ $orang->is_premium ? 's-paid' : 's-pending' }}">
                        {{ $orang->is_premium ? 'Berlangganan' : 'Belum beli' }}
                    </span>
                </div>
            @empty
                <p class="af-empty">Belum ada yang bergabung lewat tautan Anda.</p>
            @endforelse
        </div>
    </section>

    <script>
    (function () {
        var akar = document.querySelector('[data-affiliate]');
        if (!akar) return;

        /* ---------- salin tautan ---------- */
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var teks = btn.dataset.copy;
                var beres = function () {
                    var asal = btn.textContent;
                    btn.textContent = 'Tersalin';
                    setTimeout(function () { btn.textContent = asal; }, 1200);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(teks).then(beres);
                } else {
                    var el = document.getElementById('af-link');
                    el.select(); try { document.execCommand('copy'); beres(); } catch (e) {}
                }
            });
        });

        /* ---------- grafik ---------- */
        var wadah = document.getElementById('af-chart');

        function rupiahRingkas(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0', '') + ' jt';
            if (n >= 1000)    return (n / 1000).toFixed(0) + ' rb';
            return String(n);
        }

        function gambar(series) {
            var L = series.labels, K = series.commission, T = series.transactions;
            var w = wadah.clientWidth || 320, h = 190;
            var padL = 46, padR = 34, padB = 26, padT = 12;
            var iw = Math.max(10, w - padL - padR), ih = h - padT - padB;

            var maxK = Math.max.apply(null, K.concat([1]));
            var maxT = Math.max.apply(null, T.concat([1]));

            function x(i) { return padL + (L.length === 1 ? iw / 2 : iw * i / (L.length - 1)); }
            function yK(v) { return padT + ih - (v / maxK) * ih; }
            function yT(v) { return padT + ih - (v / maxT) * ih; }

            function jalur(arr, fy) {
                return arr.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + fy(v).toFixed(1); }).join(' ');
            }

            var g = ['<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none">'];

            // garis bantu + label sumbu
            for (var i = 0; i <= 4; i++) {
                var yy = padT + ih * i / 4;
                g.push('<line x1="' + padL + '" y1="' + yy + '" x2="' + (w - padR) + '" y2="' + yy + '" stroke="currentColor" stroke-opacity=".08"/>');
                g.push('<text x="4" y="' + (yy + 4) + '" font-size="9" fill="currentColor" fill-opacity=".45">Rp ' + rupiahRingkas(Math.round(maxK * (4 - i) / 4)) + '</text>');
                g.push('<text x="' + (w - padR + 6) + '" y="' + (yy + 4) + '" font-size="9" fill="currentColor" fill-opacity=".45">' + Math.round(maxT * (4 - i) / 4) + '</text>');
            }

            g.push('<path d="' + jalur(K, yK) + '" fill="none" stroke="#f97316" stroke-width="2"/>');
            g.push('<path d="' + jalur(T, yT) + '" fill="none" stroke="#22c55e" stroke-width="2" stroke-dasharray="4 3"/>');

            K.forEach(function (v, i) {
                g.push('<circle cx="' + x(i).toFixed(1) + '" cy="' + yK(v).toFixed(1) + '" r="2.5" fill="#f97316"><title>' + L[i] + ' — Rp ' + v.toLocaleString('id-ID') + ' / ' + T[i] + ' transaksi</title></circle>');
            });

            var langkah = Math.ceil(L.length / 5);
            L.forEach(function (lb, i) {
                if (i % langkah === 0 || i === L.length - 1) {
                    g.push('<text x="' + x(i).toFixed(1) + '" y="' + (h - 6) + '" font-size="9" text-anchor="middle" fill="currentColor" fill-opacity=".5">' + lb + '</text>');
                }
            });

            g.push('</svg>');
            wadah.innerHTML = g.join('');
        }

        var seriesSekarang = JSON.parse(wadah.dataset.series);
        gambar(seriesSekarang);
        window.addEventListener('resize', function () { gambar(seriesSekarang); });

        /* ---------- pembaruan langsung ----------
           Halaman menarik data tiap 10 detik. Begitu ada orang membayar
           lewat tautan referral, angka di layar ikut naik tanpa refresh.
           Berhenti sendiri saat tab tidak terlihat agar tidak boros. */
        var rupiah = function (n) { return 'Rp ' + Number(n).toLocaleString('id-ID', {maximumFractionDigits: 0}); };
        var persen = function (n) { return String(Number(n)).replace('.', ',') + '%'; };

        function pasang(nama, nilai) {
            document.querySelectorAll('[data-af="' + nama + '"]').forEach(function (el) {
                if (el.textContent !== nilai) {
                    el.textContent = nilai;
                    el.classList.add('af-flash-up');
                    setTimeout(function () { el.classList.remove('af-flash-up'); }, 900);
                }
            });
        }

        function tarik() {
            if (document.hidden) return;

            fetch(akar.dataset.statsUrl + '?range=' + akar.dataset.range, {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                pasang('commission',  rupiah(d.summary.commission));
                pasang('commission2', rupiah(d.summary.commission));
                pasang('balance',     rupiah(d.summary.balance));
                pasang('rate',        persen(d.summary.rate));
                pasang('rate2',       persen(d.summary.rate));
                pasang('transactions',  String(d.summary.transactions));
                pasang('transactions2', String(d.summary.transactions));
                pasang('total_referrals', String(d.summary.total_referrals));
                pasang('level', String(d.summary.level));

                seriesSekarang = d.series;
                gambar(seriesSekarang);
            })
            .catch(function () { /* jaringan putus sesaat bukan alasan merusak halaman */ });
        }

        setInterval(tarik, 10000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) tarik(); });
    })();
    </script>

    <script>
    (function () {
        var kolom = document.querySelector('[data-tarik-jumlah]');

        if (!kolom) return;

        var form  = document.querySelector('[data-tarik-form]');
        var semua = form.querySelector('[data-tarik-semua]');
        var info  = form.querySelector('[data-tarik-info]');

        var saldo = parseFloat(kolom.dataset.saldo) || 0;
        var min   = parseFloat(kolom.dataset.min) || 0;
        var fee   = parseFloat(kolom.dataset.fee) || 0;

        var rupiah = function (n) {
            return 'Rp ' + Math.round(n).toLocaleString('id-ID');
        };

        /*
        | Yang ditampilkan adalah jumlah yang BENAR-BENAR diterima, bukan
        | jumlah yang diketik.
        |
        | Potongan sekian persen adalah angka yang tidak bisa dihitung
        | siapa pun sambil mengetik, dan selisihnya baru terasa saat uangnya
        | masuk — saat itu yang muncul bukan pertanyaan, melainkan tuduhan.
        */
        var hitung = function () {
            var jumlah = parseFloat(kolom.value);

            if (!jumlah || jumlah <= 0) {
                info.textContent = 'Saldo tersedia ' + rupiah(saldo)
                    + '. Minimal penarikan ' + rupiah(min) + '.';

                return;
            }

            if (jumlah > saldo) {
                info.textContent = 'Melebihi saldo tersedia (' + rupiah(saldo) + ').';

                return;
            }

            if (jumlah < min) {
                info.textContent = 'Di bawah minimal penarikan ' + rupiah(min) + '.';

                return;
            }

            var potongan = jumlah * fee / 100;

            info.textContent = fee > 0
                ? 'Potongan ' + rupiah(potongan) + ' — diterima ' + rupiah(jumlah - potongan)
                    + '. Sisa saldo ' + rupiah(saldo - jumlah) + '.'
                : 'Diterima ' + rupiah(jumlah) + '. Sisa saldo ' + rupiah(saldo - jumlah) + '.';
        };

        semua.addEventListener('click', function () {
            // Dibulatkan ke bawah: mengisi 100000.004 lalu ditolak server
            // karena melebihi saldo adalah cara terburuk tombol ini gagal.
            kolom.value = Math.floor(saldo * 100) / 100;

            hitung();
        });

        kolom.addEventListener('input', hitung);

        hitung();
    })();
    </script>

@endsection
