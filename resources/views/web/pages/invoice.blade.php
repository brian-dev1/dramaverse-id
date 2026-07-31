@extends('web.layouts.app')

@section('title', 'Tagihan '.$invoice->number)

@section('content')

    <section class="section-pad">

        <h1 class="page-title">Tagihan {{ $invoice->number }}</h1>

        <p class="page-subtitle">
            {{ $invoice->plan_name }} — {{ $invoice->plan_duration }} hari
        </p>

        <div class="panel">
            <div class="detail-body-admin">
                <dl class="settings-meta">
                    <dt>Subtotal</dt>
                    <dd>Rp {{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</dd>

                    @if ((float) $invoice->fee > 0)
                        <dt>Biaya layanan</dt>
                        <dd>Rp {{ number_format((float) $invoice->fee, 0, ',', '.') }}</dd>
                    @endif

                    <dt>Total</dt>
                    <dd><strong>Rp {{ number_format((float) $invoice->total, 0, ',', '.') }}</strong></dd>

                    <dt>Status</dt>
                    <dd>
                        <span class="badge {{ $invoice->status->badge() }}">
                            {{ $invoice->status->label() }}
                        </span>
                    </dd>

                    @if ($invoice->due_at && $invoice->status->value === 'pending')
                        <dt>Bayar sebelum</dt>
                        <dd>{{ $invoice->due_at->format('d M Y H:i') }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if ($invoice->status->value === 'paid')

            <div class="panel">
                <div class="detail-body-admin">
                    <p class="page-subtitle">
                        Pembayaran diterima. Membership Anda sudah aktif
                        @if ($invoice->subscription?->expired_at)
                            sampai {{ $invoice->subscription->expired_at->format('d M Y') }}.
                        @else
                            .
                        @endif
                    </p>

                    <a href="{{ route('web.profile') }}" class="btn btn-primary">Ke profil saya</a>
                </div>
            </div>

        @elseif ($invoice->isPayable())

            <div class="panel">
                <div class="panel-head"><h2>Cara membayar</h2></div>

                <div class="detail-body-admin">
                    @if ($provider?->instruction)
                        <p class="page-subtitle">{!! nl2br(e($provider->instruction)) !!}</p>
                    @endif

                    @if ($provider && $provider->driver->isManual())
                        <dl class="settings-meta">
                            <dt>Bank</dt>
                            <dd>{{ $provider->credential('bank_name') ?? '—' }}</dd>

                            <dt>Nomor rekening</dt>
                            <dd><strong>{{ $provider->credential('account_number') ?? '—' }}</strong></dd>

                            <dt>Atas nama</dt>
                            <dd>{{ $provider->credential('account_name') ?? '—' }}</dd>

                            <dt>Nominal</dt>
                            <dd><strong>Rp {{ number_format((float) $invoice->total, 0, ',', '.') }}</strong></dd>
                        </dl>

                        <p class="page-subtitle">
                            Transfer sesuai nominal di atas, lalu kirim bukti transfernya
                            ke admin lewat bot Telegram. Membership aktif setelah
                            pembayaran diverifikasi.
                        </p>
                    @elseif ($transaction?->checkout_url)

                        {{--
                            Trakteer menyambungkan pembayaran ke tagihan lewat
                            PESAN yang diketik pendukung — tidak ada tempat lain
                            untuk menaruh referensi. Nomornya sudah diisikan ke
                            tautan, tetapi pengguna bisa menghapusnya tanpa
                            sengaja, dan pembayaran tanpa nomor tidak
                            tersambung ke tagihan mana pun.

                            Karena itu nomornya ditampilkan besar-besar di sini
                            dengan peringatan yang jelas.
                        --}}
                        @if ($provider?->driver->value === 'trakteer')
                            @php
                                // Trakteer menjual per satuan. Jumlah unit
                                // dibulatkan KE ATAS: mengirim kurang satu unit
                                // berarti tagihan tidak pernah lunas.
                                $hargaUnit = (float) ($provider->credential('unit_price') ?? 0);
                                $namaUnit  = $provider->credential('unit_name') ?: 'unit';
                                $sisa      = $invoice->outstanding();
                                $unit      = $hargaUnit > 0 ? (int) ceil($sisa / $hargaUnit) : null;
                            @endphp

                            <div class="detail-body-admin">
                                <p class="page-subtitle">
                                    <strong>Jangan hapus pesan otomatisnya.</strong>
                                    Kolom pesan di Trakteer sudah terisi nomor tagihan
                                    di bawah ini. Nomor itulah yang menyambungkan
                                    pembayaran Anda ke tagihan ini — tanpa nomor itu,
                                    membership tidak aktif otomatis.
                                </p>

                                <dl class="settings-meta">
                                    <dt>Tulis di kolom pesan</dt>
                                    <dd><strong>{{ $invoice->number }}</strong></dd>

                                    @if ($unit)
                                        <dt>Kirim sebanyak</dt>
                                        <dd>
                                            <strong>{{ $unit }} {{ $namaUnit }}</strong>
                                            <span class="cell-empty">
                                                (Rp {{ number_format($hargaUnit, 0, ',', '.') }} per {{ $namaUnit }})
                                            </span>
                                        </dd>
                                    @endif

                                    <dt>{{ (float) $invoice->paid_amount > 0 ? 'Sisa yang harus dibayar' : 'Nominal' }}</dt>
                                    <dd><strong>Rp {{ number_format($sisa, 0, ',', '.') }}</strong></dd>

                                    @if ((float) $invoice->paid_amount > 0)
                                        <dt>Sudah masuk</dt>
                                        <dd>
                                            Rp {{ number_format((float) $invoice->paid_amount, 0, ',', '.') }}
                                            <span class="badge badge-pending">{{ $invoice->paidPercent() }}%</span>
                                        </dd>
                                    @endif
                                </dl>

                                <p class="page-subtitle">
                                    Boleh dicicil. Setiap {{ $namaUnit }} yang masuk dijumlahkan,
                                    dan membership aktif sendiri begitu totalnya cukup —
                                    asalkan nomor tagihan selalu ikut di kolom pesan.
                                </p>
                            </div>
                        @endif

                        <a href="{{ $transaction->checkout_url }}" class="btn btn-primary"
                           target="_blank" rel="noopener">
                            Lanjutkan pembayaran
                        </a>
                    @endif
                </div>
            </div>

            @if ($providers->count() > 1)
                <div class="panel">
                    <div class="panel-head"><h2>Ganti metode</h2></div>

                    <form method="POST" action="{{ route('web.invoice.retry', $invoice->number) }}"
                          class="admin-form">
                        @csrf

                        <x-admin.field name="provider" label="Metode pembayaran" type="select" required
                                       :options="$providers->pluck('name', 'slug')->all()" />

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Ganti metode</button>
                        </div>
                    </form>
                </div>
            @endif

            <form method="POST" action="{{ route('web.invoice.cancel', $invoice->number) }}"
                  class="inline-form">
                @csrf
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Batalkan tagihan ini?')">
                    Batalkan tagihan
                </button>
            </form>

        @else

            <div class="panel">
                <div class="detail-body-admin">
                    <p class="page-subtitle">
                        Tagihan ini sudah tidak bisa dibayar.
                        <a href="{{ route('web.membership') }}">Pilih paket lagi</a>
                        untuk membuat tagihan baru.
                    </p>
                </div>
            </div>

        @endif

    </section>

@endsection
