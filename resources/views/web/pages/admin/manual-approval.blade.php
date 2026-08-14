@extends('web.layouts.admin')

@section('title', 'ACC Manual')

@section('content')

    {{--
        Halaman ini menjawab satu pertanyaan: "orang ini bilang sudah bayar,
        benar tidak?" Karena itu urutannya antrean dulu, baru pencarian —
        yang paling sering dibuka adalah daftar orang yang sudah melapor.
    --}}

    <section class="panel">
        <div class="panel-head">
            <h2>Menunggu di-ACC</h2>
            <span class="panel-meta">
                Sudah mengirim bukti lewat bot dan tagihannya masih menunggu.
                Yang paling lama menunggu ada di atas.
            </span>
        </div>

        @if ($antre->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Tidak ada yang menunggu. Semua bukti sudah diproses.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Bukti</th>
                            <th>Pengguna</th>
                            <th>Tagihan</th>
                            <th>Nominal</th>
                            <th>Metode</th>
                            <th>Dikirim</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($antre as $tx)
                            <tr>
                                <td>
                                    @if ($tx->proofUrl())
                                        <a href="{{ $tx->proofUrl() }}" target="_blank" rel="noopener">
                                            <img src="{{ $tx->proofUrl() }}" alt="Bukti bayar"
                                                 style="width:64px;height:64px;object-fit:cover;border-radius:6px;">
                                        </a>
                                    @else
                                        <span class="cell-empty">berkas tidak tersimpan</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $tx->invoice->user?->name ?? '—' }}
                                    <br><span class="cell-empty">
                                        ID {{ $tx->invoice->user_id }}
                                        @if ($tx->invoice->user?->telegram_username)
                                            · &#64;{{ $tx->invoice->user->telegram_username }}
                                        @endif
                                    </span>
                                    <br>
                                    <a href="{{ route('admin.manual-approval.index', ['q' => $tx->invoice->user_id]) }}"
                                       class="cell-empty">Buka detail</a>
                                </td>
                                <td>
                                    <code>{{ $tx->invoice->number }}</code>
                                    <br><span class="cell-empty">{{ $tx->invoice->plan_name }}</span>
                                </td>
                                <td>
                                    Rp {{ number_format((float) $tx->amount, 0, ',', '.') }}
                                    @if ($tx->proof_note)
                                        <br><span class="cell-empty">“{{ Str::limit($tx->proof_note, 60) }}”</span>
                                    @endif
                                </td>
                                <td>{{ $tx->provider?->name ?? ($tx->method ?? '—') }}</td>
                                <td>
                                    {{ $tx->proof_uploaded_at?->diffForHumans() }}
                                    <br><span class="cell-empty">
                                        {{ \App\Support\Waktu::ringkas($tx->proof_uploaded_at) }}
                                    </span>
                                </td>
                                <td class="col-actions">
                                    <button type="submit" class="btn btn-sm btn-primary" form="acc-{{ $tx->id }}"
                                            onclick="return confirm('ACC tagihan {{ $tx->invoice->number }}? Membership langsung aktif.')">
                                        ACC
                                    </button>
                                    <button type="submit" class="btn btn-sm btn-danger" form="tolak-{{ $tx->id }}"
                                            onclick="return confirm('Tolak bukti ini? Tagihan tetap menunggu.')">
                                        Tolak bukti
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Form aksi di luar tabel; lihat catatan form bersarang di STATUS.md. --}}
            @foreach ($antre as $tx)
                <form method="POST" action="{{ route('admin.manual-approval.approve', $tx->id) }}"
                      id="acc-{{ $tx->id }}" class="inline-form">@csrf</form>
                <form method="POST" action="{{ route('admin.manual-approval.reject', $tx->id) }}"
                      id="tolak-{{ $tx->id }}" class="inline-form">@csrf</form>
            @endforeach
        @endif
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Cari pengguna</h2>
            <span class="panel-meta">
                ID pengguna, ID Telegram, &#64;username, nama, atau email.
            </span>
        </div>

        <form method="GET" action="{{ route('admin.manual-approval.index') }}" class="admin-form">
            <x-admin.field name="q" label="ID pengguna / &#64;username / nama" :value="$q"
                           hint="Angka dicoba sebagai ID pengguna dulu, baru sebagai ID Telegram." />

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Cari</button>
                @if ($q !== '')
                    <a href="{{ route('admin.manual-approval.index') }}" class="btn">Bersihkan</a>
                @endif
            </div>
        </form>
    </section>

    @if ($q !== '' && $user === null)
        <section class="panel">
            <div class="panel-head">
                <h2>
                    @if ($kandidat->isEmpty())
                        Tidak ditemukan
                    @else
                        {{ $kandidat->count() }} pengguna cocok — pilih satu
                    @endif
                </h2>
            </div>

            @if ($kandidat->isEmpty())
                <div class="detail-body-admin">
                    <p class="page-subtitle">
                        Tidak ada pengguna yang cocok dengan <code>{{ $q }}</code>.
                        Pengguna baru muncul di sini setelah menekan /start di bot.
                    </p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama</th>
                                <th>Telegram</th>
                                <th>Status</th>
                                <th class="col-actions">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($kandidat as $k)
                                <tr>
                                    <td><code>{{ $k->id }}</code></td>
                                    <td>{{ $k->name }}</td>
                                    <td>
                                        @if ($k->telegram_username)
                                            &#64;{{ $k->telegram_username }}
                                        @else
                                            <span class="cell-empty">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $k->is_premium ? 'badge-on' : 'badge-off' }}">
                                            {{ $k->is_premium ? 'Premium' : 'Gratis' }}
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <a href="{{ route('admin.manual-approval.index', ['q' => $k->id]) }}"
                                           class="btn btn-sm">Pilih</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($user !== null)

        <section class="panel">
            <div class="panel-head">
                <h2>{{ $user->name }}</h2>
                <span class="panel-meta">
                    ID <code>{{ $user->id }}</code>
                    @if ($user->telegram_id)
                        · Telegram <code>{{ $user->telegram_id }}</code>
                    @endif
                    @if ($user->telegram_username)
                        · &#64;{{ $user->telegram_username }}
                    @endif
                </span>
            </div>

            <div class="detail-body-admin">
                <p class="page-subtitle">
                    Status membership:
                    <span class="badge {{ ($status['status'] ?? '') === 'premium' ? 'badge-on' : 'badge-off' }}">
                        {{ $status['label'] ?? '—' }}
                    </span>
                    @if (! empty($status['expires_at']))
                        sampai
                        <strong>{{ \App\Support\Waktu::ringkas($status['expires_at']) }}</strong>
                    @endif
                    @if (! empty($status['plan']))
                        · paket {{ $status['plan'] }}
                    @endif
                </p>

                <p class="page-subtitle">
                    <a href="{{ route('admin.user.show', $user->id) }}" class="btn btn-sm">Detail pengguna</a>
                </p>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Tagihan</h2>
                <span class="panel-meta">
                    Yang masih menunggu ditampilkan lebih dulu. Maksimal 20 terakhir.
                </span>
            </div>

            @if ($invoices->isEmpty())
                <div class="detail-body-admin">
                    <p class="page-subtitle">
                        Pengguna ini belum pernah membuat tagihan. Minta ia menekan
                        <code>/vip</code> di bot dan memilih paket lebih dulu — ACC hanya
                        bisa dilakukan atas tagihan yang sudah ada, supaya paket dan masa
                        aktifnya jelas tercatat.
                    </p>
                </div>
            @else
                @foreach ($invoices as $invoice)
                    @php
                        $tx = $invoice->transactions->sortByDesc('id')->first();
                    @endphp

                    <div class="detail-body-admin" style="border-top:1px solid var(--admin-border,#2a2a2a);padding-top:1rem;">
                        <p class="page-subtitle">
                            <code>{{ $invoice->number }}</code>
                            · {{ $invoice->plan_name }} ({{ $invoice->durasi_tampil }})
                            · <strong>Rp {{ number_format((float) $invoice->total, 0, ',', '.') }}</strong>
                            @if ((float) $invoice->paid_amount > 0 && ! $invoice->isSettled())
                                <br>Terbayar Rp {{ number_format((float) $invoice->paid_amount, 0, ',', '.') }}
                                ({{ $invoice->paidPercent() }}%) — sisa
                                Rp {{ number_format($invoice->outstanding(), 0, ',', '.') }}
                            @endif
                            <br>
                            <span class="badge {{ $invoice->status === \App\Enums\PaymentStatus::PAID ? 'badge-on' : 'badge-pending' }}">
                                {{ $invoice->status->label() }}
                            </span>
                            <span class="cell-empty">
                                dibuat {{ \App\Support\Waktu::ringkas($invoice->created_at) }}
                                @if ($invoice->due_at)
                                    · tempo {{ \App\Support\Waktu::ringkas($invoice->due_at) }}
                                    @if ($invoice->isOverdue())
                                        <span class="queue-error">(lewat tempo)</span>
                                    @endif
                                @endif
                            </span>
                            @if ($tx)
                                <br><span class="cell-empty">
                                    {{ $tx->provider?->name ?? 'tanpa provider' }}
                                    · ref <code>{{ $tx->reference }}</code>
                                </span>
                            @endif
                        </p>

                        @if ($tx?->proofUrl())
                            <p class="page-subtitle">
                                <a href="{{ $tx->proofUrl() }}" target="_blank" rel="noopener">
                                    <img src="{{ $tx->proofUrl() }}" alt="Bukti bayar"
                                         style="max-width:220px;border-radius:8px;">
                                </a>
                                <br><span class="cell-empty">
                                    Dikirim {{ \App\Support\Waktu::ringkas($tx->proof_uploaded_at) }}
                                    @if ($tx->proof_note)
                                        — “{{ $tx->proof_note }}”
                                    @endif
                                </span>
                            </p>
                        @elseif ($tx?->proof_note)
                            <p class="page-subtitle cell-empty">{{ $tx->proof_note }}</p>
                        @endif

                        @if ($tx && $invoice->status === \App\Enums\PaymentStatus::PENDING)
                            <form method="POST" action="{{ route('admin.manual-approval.approve', $tx->id) }}"
                                  class="admin-form">
                                @csrf

                                {{--
                                    Ditulis langsung, bukan lewat <x-admin.field>.
                                    Komponen itu menurunkan id dari nama field, dan
                                    di dalam perulangan ini setiap tagihan akan
                                    menghasilkan id `field-note` yang sama — label
                                    yang diklik lalu memfokuskan input tagihan
                                    pertama, bukan tagihan yang sedang dilihat.
                                --}}
                                <div class="field">
                                    <label for="note-{{ $tx->id }}">Catatan verifikasi</label>
                                    <input type="text" id="note-{{ $tx->id }}" name="note"
                                           class="control" maxlength="255"
                                           placeholder="cocok mutasi BCA 07/08 14:32">
                                    <p class="field-hint">
                                        Opsional, tapi sangat menolong saat ada sengketa
                                        berbulan-bulan kemudian.
                                    </p>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary"
                                            onclick="return confirm('ACC tagihan {{ $invoice->number }}? Membership langsung aktif.')">
                                        <x-web.home.icon name="check" :size="14" />
                                        ACC &amp; aktifkan membership
                                    </button>
                                    <a href="{{ route('admin.invoice.show', $invoice->number) }}" class="btn">
                                        Lihat tagihan
                                    </a>
                                </div>
                            </form>
                        @else
                            <p class="page-subtitle">
                                <a href="{{ route('admin.invoice.show', $invoice->number) }}" class="btn btn-sm">
                                    Lihat tagihan
                                </a>
                            </p>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>

    @endif

@endsection
