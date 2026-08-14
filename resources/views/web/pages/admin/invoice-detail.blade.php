@extends('web.layouts.admin')

@section('title', 'Tagihan '.$invoice->number)

@section('content')

    <div class="admin-grid">

        <section class="panel">
            <div class="panel-head">
                <h2>{{ $invoice->number }}</h2>
                <span class="panel-meta">{{ $invoice->created_at?->ringkas() ?? '—' }}</span>
            </div>

            <div class="detail-body-admin">
                <dl class="settings-meta">
                    <dt>Pengguna</dt>
                    <dd>{{ $invoice->user?->name ?? '—' }}</dd>

                    <dt>Paket</dt>
                    <dd>{{ $invoice->plan_name }} ({{ $invoice->durasi_tampil }})</dd>

                    <dt>Subtotal</dt>
                    <dd>{{ \App\Support\Uang::invoice($invoice, 'subtotal') }}</dd>

                    <dt>Biaya layanan</dt>
                    <dd>{{ \App\Support\Uang::invoice($invoice, 'fee') }}</dd>

                    <dt>Total</dt>
                    <dd><strong>{{ \App\Support\Uang::invoice($invoice) }}</strong></dd>

                    <dt>Status</dt>
                    <dd>
                        <span class="badge {{ $invoice->status->badge() }}">
                            {{ $invoice->status->label() }}
                        </span>
                    </dd>

                    <dt>Jatuh tempo</dt>
                    <dd>{{ $invoice->due_at?->lengkapRelatif() ?? '—' }}</dd>

                    <dt>Dibayar</dt>
                    <dd>{{ $invoice->paid_at?->lengkap() ?? '—' }}</dd>

                    @if ($invoice->note)
                        <dt>Catatan</dt>
                        <dd>{{ $invoice->note }}</dd>
                    @endif
                </dl>

                @if ($invoice->status->value === 'pending')
                    <form method="POST" action="{{ route('admin.invoice.cancel', $invoice->number) }}"
                          class="admin-form">
                        @csrf
                        <x-admin.field name="note" label="Alasan pembatalan" :value="old('note')" />
                        <div class="form-actions">
                            <button type="submit" class="btn btn-danger">
                                <x-web.home.icon name="close" :size="14" /> Batalkan tagihan
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Langganan</h2></div>

            <div class="detail-body-admin">
                @if ($invoice->subscription)
                    <dl class="settings-meta">
                        <dt>Status</dt>
                        <dd>{{ $invoice->subscription->status }}</dd>

                        <dt>Mulai</dt>
                        <dd>{{ $invoice->subscription->started_at?->lengkap() ?? '—' }}</dd>

                        <dt>Berakhir</dt>
                        <dd>{{ $invoice->subscription->expired_at?->lengkapRelatif() ?? '—' }}</dd>

                        <dt>Sumber</dt>
                        <dd>{{ $invoice->subscription->source }}</dd>
                    </dl>
                @else
                    <p class="page-subtitle">Belum ada langganan untuk tagihan ini.</p>
                @endif
            </div>
        </section>

    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Percobaan pembayaran</h2>
            <span class="panel-meta">
                Beberapa baris adalah keadaan normal — pengguna yang gagal bayar lalu
                mencoba lagi dengan metode lain menghasilkan baris baru
            </span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Referensi</th>
                        <th>Metode</th>
                        <th>Nominal</th>
                        <th>Status</th>
                        <th>Refund</th>
                        <th>Verifikasi</th>
                        <th class="col-actions">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $tx)
                        <tr>
                            <td>
                                <span class="fm-key">{{ $tx->reference }}</span>
                                @if ($tx->external_id)
                                    <br><span class="cell-empty">{{ Str::limit($tx->external_id, 28) }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $tx->provider?->name ?? '—' }}
                                @if ($tx->method)
                                    <br><span class="cell-empty">{{ $tx->method }}</span>
                                @endif
                            </td>
                            <td>
                                {{ \App\Support\Uang::format($tx->amount, $invoice->currency) }}

                                {{-- Bukti bayar dari bot; lihat PaymentProofHandler. --}}
                                @if ($tx->hasProof())
                                    <br>
                                    @if ($tx->proofUrl())
                                        <a href="{{ $tx->proofUrl() }}" target="_blank" rel="noopener">
                                            <img src="{{ $tx->proofUrl() }}" alt="Bukti bayar"
                                                 style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                                        </a>
                                    @else
                                        <span class="cell-empty">bukti terkirim, berkas tidak tersimpan</span>
                                    @endif
                                    <br><span class="cell-empty">
                                        {{ $tx->proof_uploaded_at?->ringkas() ?? '—' }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $tx->status->badge() }}">{{ $tx->status->label() }}</span>
                                @if ($tx->last_error)
                                    <br><span class="queue-error">{{ $tx->last_error }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $tx->refund_status->badge() }}">
                                    {{ $tx->refund_status->label() }}
                                </span>
                            </td>
                            <td>
                                {{ $tx->verified_at?->ringkas() ?? '—' }}
                                @if ($tx->verify_attempts > 0)
                                    <br><span class="cell-empty">{{ $tx->verify_attempts }}x dicek</span>
                                @endif
                            </td>
                            <td class="col-actions">
                                @if ($tx->status->value === 'pending')
                                    <button type="submit" class="btn btn-sm" form="verify-{{ $tx->id }}"
                                            onclick="return confirm('Tandai lunas dan aktifkan membership?')">
                                        <x-web.home.icon name="check" :size="14" /> Verifikasi
                                    </button>
                                @else
                                    <span class="cell-empty">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{--
            Form verifikasi ditutup DI LUAR tabel, dihubungkan lewat atribut
            `form`. Form bersarang dibuang parser HTML — bug yang masih
            tercatat di STATUS.md untuk modul CRUD lain.
        --}}
        @foreach ($transactions as $tx)
            @if ($tx->status->value === 'pending')
                <form method="POST" action="{{ route('admin.invoice.verify', $tx->id) }}"
                      id="verify-{{ $tx->id }}" class="inline-form">
                    @csrf
                </form>
            @endif
        @endforeach
    </section>

@endsection
