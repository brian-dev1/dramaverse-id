@extends('web.layouts.admin')

@section('title', 'Kirim Poster ke Partner')

@section('content')

    @if ($penghalang)
        {{-- Ditampilkan di paling atas, bukan sebagai galat setelah tombol
             ditekan. Admin harus tahu sebelum memilih apa pun bahwa
             pengiriman belum mungkin dilakukan. --}}
        <div class="toast toast-error" role="alert">
            <x-web.home.icon name="close" :size="15" />
            {{ $penghalang }}
        </div>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Grup partner</h2>
            <span class="panel-meta">
                @if ($chatId)
                    <code>{{ $chatId }}</code>
                    @if ($threadId)
                        · topik <code>{{ $threadId }}</code>
                    @else
                        · topik General
                    @endif
                @else
                    Belum diatur
                @endif
            </span>
        </div>

        <div class="detail-body-admin">
            <p class="page-subtitle">
                Poster dan judul drama dikirim ke grup partner, satu poster satu pesan.
                Ini <strong>bukan</strong> kiriman ke channel — isinya tidak memuat daftar
                episode maupun tautan bot, supaya partner bisa menyalin judulnya apa adanya
                ke media sosial mereka.
            </p>

            <p class="page-subtitle">
                Chat ID dan ID topik diatur di
                <a href="{{ route('admin.settings') }}">Pengaturan → Grup Partner</a>.
            </p>

            <ul class="page-subtitle">
                <li><strong>{{ $belum }}</strong> drama belum pernah dikirim ke grup ini.</li>
                <li><strong>{{ $kandidat->count() }}</strong> drama punya poster.</li>
                @if ($tanpaPoster > 0)
                    <li>{{ $tanpaPoster }} drama tidak ikut karena belum punya poster.</li>
                @endif
            </ul>

            <form method="POST" action="{{ route('admin.partner-poster.bulk') }}"
                      data-confirm
                      data-confirm-title="Kirim poster ke grup partner?"
                      data-confirm-ok="Kirim sekarang"
                      data-confirm-message="{{ min($belum, $limit) }} poster akan dikirim ke grup partner satu per satu. Kiriman tidak bisa ditarik dari panel.">
                @csrf

                <button type="submit" class="btn btn-primary"
                        @disabled($penghalang !== null || $belum === 0)>
                    Kirim semua yang belum ({{ min($belum, $limit) }})
                </button>
            </form>

            <p class="page-subtitle" style="margin-top:.6rem">
                Maksimal {{ $limit }} poster sekali klik, berjeda {{ $jeda }} detik satu sama
                lain supaya tidak kena batas kiriman Telegram. Sisanya tinggal tekan tombol
                yang sama lagi setelah antrean ini selesai.
            </p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Daftar drama</h2>
            <span class="panel-meta">{{ $kandidat->count() }} punya poster</span>
        </div>

        <div class="table-wrap" style="max-height:520px;overflow:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Drama</th>
                        <th>Part</th>
                        <th>Keadaan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($kandidat as $d)
                        <tr>
                            <td>{{ $d->title }}</td>
                            <td>{{ $d->total_episode ?: '—' }}</td>
                            <td>
                                @if (isset($sudahDikirim[$d->id]))
                                    <span class="badge badge-on">Sudah dikirim</span>
                                @else
                                    <span class="badge badge-pending">Belum</span>
                                @endif
                            </td>
                            <td>
                                {{-- Tombol satuan selalu mengirim, termasuk yang sudah
                                     pernah. Gunanya justru untuk mengulang kiriman yang
                                     gagal atau terlanjur terhapus dari grup. --}}
                                <form method="POST"
                                      action="{{ route('admin.partner-poster.one', $d) }}">
                                    @csrf

                                    <button type="submit" class="btn btn-sm"
                                            @disabled($penghalang !== null)>
                                        {{ isset($sudahDikirim[$d->id]) ? 'Kirim ulang' : 'Kirim' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="muted">
                                Belum ada drama yang punya poster.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Riwayat</h2>
            <span class="panel-meta">30 terakhir</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Drama</th>
                        <th>Keadaan</th>
                        <th>Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $r)
                        <tr>
                            <td>{{ $r->created_at?->format('d M Y H:i') }}</td>
                            <td>{{ $r->drama?->title ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $r->statusBadge() }}">
                                    {{ $r->statusLabel() }}
                                </span>

                                @if ($r->error)
                                    <div class="muted" style="font-size:.82em">{{ $r->error }}</div>
                                @endif
                            </td>
                            <td>{{ $r->sender?->name ?? 'sistem' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="muted">Belum ada kiriman.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

@endsection
