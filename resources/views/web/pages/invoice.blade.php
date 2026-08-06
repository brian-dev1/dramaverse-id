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

                    @if ($provider?->qris_image_path)
                        <div class="qris-payment">
                            <p class="page-subtitle">
                                <strong>Scan QRIS untuk membayar</strong>
                            </p>

                            <img
                                src="{{ asset('storage/'.$provider->qris_image_path) }}"
                                alt="QRIS {{ $provider->name }}"
                                style="display:block; width:min(100%, 360px); height:auto; margin:16px 0; border-radius:12px;"
                            >

                            <p class="page-subtitle">
                                Bayar sesuai nominal tagihan:
                                <strong>Rp {{ number_format((float) $invoice->total, 0, ',', '.') }}</strong>
                            </p>
                        </div>
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
                                // Saran jumlah unit. Trakteer mengizinkan
                                // beberapa unit dengan harga berbeda, jadi
                                // yang ditampilkan daftar pilihan -- bukan
                                // satu angka.
                                //
                                // Ini SARAN, bukan syarat. Pembayaran
                                // dicocokkan dari nominal di webhook, apa pun
                                // unit yang dipakai pendukung.
                                $sisa  = $invoice->outstanding();
                                $saran = $provider->unitSuggestions($sisa);
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

                                @if ($saran)
                                    <p class="page-subtitle">
                                        Pilih salah satu — semuanya sama-sama diterima:
                                    </p>

                                    <div class="table-wrap">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Kirim</th>
                                                    <th>Harga satuan</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($saran as $u)
                                                    <tr>
                                                        <td>
                                                            <strong>{{ $u['jumlah'] }} {{ $u['nama'] }}</strong>
                                                            @if ($u['pas'])
                                                                <span class="badge badge-on">pas</span>
                                                            @endif
                                                        </td>
                                                        <td>Rp {{ number_format($u['harga'], 0, ',', '.') }}</td>
                                                        <td>
                                                            Rp {{ number_format($u['total'], 0, ',', '.') }}
                                                            @if (! $u['pas'])
                                                                <span class="cell-empty">
                                                                    (lebih Rp {{ number_format($u['total'] - $sisa, 0, ',', '.') }})
                                                                </span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <p class="page-subtitle">
                                        Kelebihan tidak otomatis diproses — kalau memilih yang
                                        bukan "pas", hubungi admin setelah membayar.
                                    </p>
                                @endif

                                <p class="page-subtitle">
                                    Boleh dicicil. Setiap pembayaran yang masuk dijumlahkan,
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
                        Tagihan ini sudah tidak bisa dibayar. Buat tagihan baru
                        lewat bot Telegram — tekan menu <strong>Premium</strong>.
                    </p>
                </div>
            </div>

        @endif

    </section>

@endsection