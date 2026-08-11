@extends('web.layouts.admin')

@section('title', 'Analytics')

@php $chartsOnPage = true; @endphp

@section('content')

    {{--
        Tab dan periode sebagai tautan biasa, bukan JavaScript. Setiap
        kombinasi jadi URL tersendiri yang bisa di-bookmark dan dikirim ke
        orang lain — dan yang paling menentukan, hanya seksi yang dibuka yang
        dihitung server. Memuat kelimanya sekaligus berarti belasan query
        agregat berjalan untuk empat tab yang mungkin tidak dilihat.
    --}}
    <div class="admin-toolbar">
        <div class="toolbar-actions">
            @foreach ($sections as $key => $label)
                <a href="{{ route('admin.analytics', ['section' => $key, 'period' => $period->value]) }}"
                   class="btn btn-sm {{ $section === $key ? 'btn-primary' : '' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="toolbar-actions">
            @foreach ($periods as $key => $label)
                <a href="{{ route('admin.analytics', ['section' => $section, 'period' => $key]) }}"
                   class="btn btn-sm {{ $period->value === $key ? 'btn-primary' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @if ($section === 'business')

        <div class="stat-row">
            <x-admin.stat-card label="Total pengguna" :value="$data['users']['total']"   icon="users" />
            <x-admin.stat-card label="Aktif 30 hari"  :value="$data['users']['aktif']"   icon="user" />
            <x-admin.stat-card label="Premium"        :value="$data['users']['premium']" icon="star" />
            <x-admin.stat-card label="Gratis"         :value="$data['users']['gratis']"  icon="users" />
        </div>

        <div class="chart-grid">
            <x-admin.chart id="an-reg" title="Pendaftaran baru ({{ $period->label() }})" type="bar" color="#C1425B"
                           :labels="$data['registrations']['labels']" :values="$data['registrations']['values']" />
        </div>

        <div class="admin-grid">
            <section class="panel">
                <div class="panel-head">
                    <h2>Pertumbuhan</h2>
                    <span class="panel-meta">Dibanding periode sebelumnya yang sama panjang</span>
                </div>
                <div class="detail-body-admin">
                    <dl class="settings-meta">
                        {{-- Baris Pendapatan hanya ada bila controller tidak
                             membuangnya, yaitu bila pengguna punya
                             `finance.view`. Daftar dibangun dari kunci yang
                             benar-benar ada, bukan dari daftar tetap, supaya
                             tidak ada "Undefined array key" saat disaring. --}}
                        @php
                            $labelPertumbuhan = array_intersect_key([
                                'user'         => 'Pengguna baru',
                                'subscription' => 'Langganan',
                                'revenue'      => 'Pendapatan',
                            ], $data['growth']);
                        @endphp
                        @foreach ($labelPertumbuhan as $k => $label)
                            <dt>{{ $label }}</dt>
                            <dd>
                                <span class="badge {{ $data['growth'][$k]['persen'] >= 0 ? 'badge-on' : 'badge-off' }}">
                                    {{ $data['growth'][$k]['persen'] >= 0 ? '+' : '' }}{{ $data['growth'][$k]['persen'] }}%
                                </span>
                                <span class="cell-empty">
                                    {{ number_format($data['growth'][$k]['sekarang']) }}
                                    dari {{ number_format($data['growth'][$k]['sebelumnya']) }}
                                </span>
                            </dd>
                        @endforeach
                    </dl>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Ringkasan</h2></div>
                <div class="detail-body-admin">
                    <dl class="settings-meta">
                        @can('finance.view')
                            <dt>Pendapatan total</dt>
                            <dd>Rp {{ number_format($data['revenue']['total'], 0, ',', '.') }}</dd>

                            <dt>Bulan ini</dt>
                            <dd>Rp {{ number_format($data['revenue']['bulan_ini'], 0, ',', '.') }}</dd>

                            <dt>Hari ini</dt>
                            <dd>Rp {{ number_format($data['revenue']['hari_ini'], 0, ',', '.') }}</dd>
                        @endcan

                        <dt>Pengguna Telegram</dt>
                        <dd>{{ number_format($data['users']['telegram']) }}</dd>

                        <dt>Daftar hari ini</dt>
                        <dd>{{ number_format($data['users']['baru']) }}</dd>
                    </dl>
                </div>
            </section>
        </div>

    @elseif ($section === 'content')

        <div class="stat-row">
            <x-admin.stat-card label="Drama"             :value="$data['totals']['drama']"    icon="film" />
            <x-admin.stat-card label="Episode"           :value="$data['totals']['episode']"  icon="play" />
            <x-admin.stat-card label="Video tersimpan"   :value="$data['totals']['video']"    icon="database" />
            <x-admin.stat-card label="Ditonton hari ini" :value="$data['totals']['hari_ini']" icon="clock" />
        </div>

        <div class="chart-grid">
            <x-admin.chart id="an-upload" title="Unggahan ({{ $period->label() }})" type="bar" color="#5B4B8A"
                           :labels="$data['uploads']['labels']" :values="$data['uploads']['values']" />
        </div>

        <div class="admin-grid">
            <section class="panel">
                <div class="panel-head">
                    <h2>Penyelesaian tontonan</h2>
                    <span class="panel-meta">Berapa banyak yang ditonton sampai habis</span>
                </div>
                <div class="detail-body-admin">
                    <dl class="settings-meta">
                        <dt>Selesai</dt>
                        <dd>{{ number_format($data['completion']['selesai']) }}</dd>

                        <dt>Masih berjalan</dt>
                        <dd>{{ number_format($data['completion']['lanjut']) }}</dd>

                        <dt>Completion rate</dt>
                        <dd><span class="badge badge-on">{{ $data['completion']['rate'] }}%</span></dd>
                    </dl>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Paling banyak ditonton</h2></div>
                <table class="data-table">
                    <thead><tr><th>Judul</th><th>Tontonan</th><th>Riwayat</th></tr></thead>
                    <tbody>
                        @forelse ($data['topDramas'] as $drama)
                            <tr>
                                <td>{{ $drama->title }}</td>
                                <td>{{ number_format($drama->views) }}</td>
                                <td>{{ number_format($drama->watch_histories_count) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><span class="cell-empty">Belum ada data.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Episode teratas</h2></div>
                <table class="data-table">
                    <thead><tr><th>Drama</th><th>Episode</th><th>Riwayat</th></tr></thead>
                    <tbody>
                        @forelse ($data['topEpisodes'] as $episode)
                            <tr>
                                <td>{{ $episode->drama?->title ?? '—' }}</td>
                                <td>{{ $episode->episode_number }}</td>
                                <td>{{ number_format($episode->watch_histories_count) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><span class="cell-empty">Belum ada data.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Paling banyak difavoritkan</h2></div>
                <table class="data-table">
                    <thead><tr><th>Judul</th><th>Favorit</th></tr></thead>
                    <tbody>
                        @forelse ($data['topFavorite'] as $drama)
                            <tr><td>{{ $drama->title }}</td><td>{{ number_format($drama->favorites_count) }}</td></tr>
                        @empty
                            <tr><td colspan="2"><span class="cell-empty">Belum ada favorit.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>

    @elseif ($section === 'telegram')

        <div class="stat-row">
            <x-admin.stat-card label="Pengguna Telegram" :value="$data['totals']['pengguna']"   icon="send" />
            <x-admin.stat-card label="Aktif"             :value="$data['totals']['aktif']"      icon="user" />
            <x-admin.stat-card label="Diblokir"          :value="$data['totals']['diblokir']"   icon="shield" />
            <x-admin.stat-card label="Siap dikirim bot"  :value="$data['totals']['siap_kirim']" icon="check" />
        </div>

        <section class="panel">
            <div class="panel-head">
                <h2>Sinkronisasi video</h2>
                <span class="panel-meta">
                    Hanya video bertanda Tersinkron yang bisa dikirim lewat deep link
                </span>
            </div>
            <table class="data-table">
                <thead><tr><th>Status</th><th>Jumlah</th></tr></thead>
                <tbody>
                    @foreach ($data['sync'] as $status => $jumlah)
                        <tr>
                            <td>{{ \App\Enums\TelegramSyncStatus::from($status)->label() }}</td>
                            <td>{{ number_format($jumlah) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

    @elseif ($section === 'storage')

        <div class="stat-row">
            <x-admin.stat-card label="Berkas video" :value="$data['totals']['berkas']" icon="database" />
            <x-admin.stat-card label="Total ukuran"
                               :value="number_format($data['totals']['ukuran'] / 1073741824, 2).' GB'"
                               icon="chart" />
        </div>

        <div class="chart-grid">
            <x-admin.chart id="an-storage" title="Pertumbuhan penyimpanan ({{ $period->label() }})" color="#D9AF6E"
                           :labels="$data['growth']['labels']" :values="$data['growth']['values']" />
        </div>

        <section class="panel">
            <div class="panel-head">
                <h2>Pemakaian per provider</h2>
                <span class="panel-meta">Dibaca dari database, bukan dari isi bucket</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Provider</th><th>Berkas</th><th>Ukuran</th></tr></thead>
                <tbody>
                    @forelse ($data['providers'] as $baris)
                        <tr>
                            <td>{{ $baris->provider?->name ?? 'Tanpa provider' }}</td>
                            <td>{{ number_format($baris->berkas) }}</td>
                            <td>{{ number_format($baris->ukuran / 1073741824, 2) }} GB</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><span class="cell-empty">Belum ada berkas.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    @else

        <div class="stat-row">
            <x-admin.stat-card label="Pendapatan total"
                               :value="'Rp '.number_format($data['revenue']['total'], 0, ',', '.')" icon="card" />
            <x-admin.stat-card label="Bulan ini"
                               :value="'Rp '.number_format($data['revenue']['bulan_ini'], 0, ',', '.')" icon="chart" />
            <x-admin.stat-card label="Tagihan lunas" :value="$data['revenue']['invoice']" icon="check" />
            <x-admin.stat-card label="Payment success rate"
                               :value="$data['success']['rate'].'%'" icon="trend" />
        </div>

        <div class="chart-grid">
            <x-admin.chart id="an-revenue" title="Pendapatan ({{ $period->label() }})" color="#EAC98C" money
                           :labels="$data['perPeriod']['labels']" :values="$data['perPeriod']['values']" />
        </div>

        <div class="admin-grid">
            <section class="panel">
                <div class="panel-head"><h2>Tagihan</h2></div>
                <table class="data-table">
                    <thead><tr><th>Status</th><th>Jumlah</th></tr></thead>
                    <tbody>
                        @foreach ($data['invoices'] as $status => $jumlah)
                            <tr>
                                <td>{{ \App\Enums\PaymentStatus::from($status)->label() }}</td>
                                <td>{{ number_format($jumlah) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Langganan</h2></div>
                <table class="data-table">
                    <thead><tr><th>Status</th><th>Jumlah</th></tr></thead>
                    <tbody>
                        @foreach ($data['subscriptions'] as $status => $jumlah)
                            <tr>
                                <td>{{ \App\Enums\SubscriptionStatus::from($status)->label() }}</td>
                                <td>{{ number_format($jumlah) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <h2>Perpanjangan</h2>
                    <span class="panel-meta">Pelanggan yang membayar lebih dari sekali</span>
                </div>
                <div class="detail-body-admin">
                    <dl class="settings-meta">
                        <dt>Pelanggan membayar</dt>
                        <dd>{{ number_format($data['renewal']['pelanggan']) }}</dd>

                        <dt>Berulang</dt>
                        <dd>{{ number_format($data['renewal']['berulang']) }}</dd>

                        <dt>Renewal rate</dt>
                        <dd><span class="badge badge-on">{{ $data['renewal']['rate'] }}%</span></dd>
                    </dl>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Pendapatan per paket</h2></div>
                <table class="data-table">
                    <thead><tr><th>Paket</th><th>Terjual</th><th>Pendapatan</th></tr></thead>
                    <tbody>
                        @forelse ($data['perPlan'] as $baris)
                            <tr>
                                <td>{{ $baris->plan_name }}</td>
                                <td>{{ number_format($baris->jumlah) }}</td>
                                <td>Rp {{ number_format($baris->pendapatan, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><span class="cell-empty">Belum ada tagihan lunas.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>

    @endif

@endsection
