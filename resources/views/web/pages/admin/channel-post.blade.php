@extends('web.layouts.admin')

@section('title', 'Kirim ke Channel')

@section('content')

    @if ($penghalang)
        {{-- Penghalang ditampilkan di paling atas, bukan sebagai galat setelah
             tombol ditekan. Admin harus tahu sebelum menyusun captionnya bahwa
             pengiriman belum mungkin dilakukan. --}}
        <div class="toast toast-error" role="alert">
            <x-web.home.icon name="close" :size="15" />
            {{ $penghalang }}
        </div>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Pilih drama dan rentang episode</h2>
            <span class="panel-meta">
                @if ($chatId)
                    Channel tujuan: <code>{{ $chatId }}</code>
                @else
                    Channel belum diatur
                @endif
            </span>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.channel-post.index') }}" class="toolbar-search">

                <select name="drama_id" class="control control-sm" required>
                    <option value="">— pilih drama —</option>
                    @foreach ($dramas as $d)
                        <option value="{{ $d->id }}" @selected($drama?->id === $d->id)>
                            {{ $d->title }}@if ($d->total_episode) ({{ $d->total_episode }} ep)@endif
                        </option>
                    @endforeach
                </select>

                <input type="number" name="from" value="{{ $dari }}" min="1"
                       class="control control-sm" placeholder="Dari episode" style="max-width:130px">

                <input type="number" name="to" value="{{ $sampai }}" min="1"
                       class="control control-sm" placeholder="Sampai episode" style="max-width:140px">

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" /> Pratinjau
                </button>
            </form>

            <span class="panel-meta">Kosongkan rentang untuk mengirim seluruh episode.</span>
        </div>
    </section>

    @if ($drama)

        <section class="panel">
            <div class="panel-head">
                <h2>Pratinjau</h2>
                <span class="panel-meta">
                    {{ $episodes->count() }} episode
                    @if (count($potongan) > 1)
                        · akan dikirim sebagai {{ count($potongan) }} pesan
                    @endif
                </span>
            </div>

            <div class="detail-body-admin">

                @if ($episodes->isEmpty())
                    <p class="page-subtitle">Tidak ada episode pada rentang itu.</p>
                @else

                    @if (count($potongan) > 1)
                        {{-- Bukan peringatan kesalahan, melainkan penjelasan.
                             Caption foto Telegram maksimal 1024 karakter, jadi
                             daftar yang panjang memang harus dipecah. --}}
                        <p class="page-subtitle">
                            Daftarnya melewati batas caption foto Telegram (1024 karakter),
                            jadi sisanya menyusul sebagai pesan teks di bawah posternya.
                        </p>
                    @endif

                    @foreach ($potongan as $i => $teks)
                        <div class="panel" style="margin-bottom:14px">
                            <div class="panel-head">
                                <h2 style="font-size:14px">
                                    Pesan {{ $i + 1 }}
                                    @if ($i === 0 && $drama->poster_url)
                                        — dengan poster
                                    @endif
                                </h2>
                                <span class="panel-meta">{{ mb_strlen(strip_tags($teks)) }} karakter</span>
                            </div>

                            <div class="detail-body-admin">
                                @if ($i === 0 && $drama->poster_url)
                                    <img src="{{ $drama->poster_url }}" alt="Poster {{ $drama->title }}"
                                         style="width:150px;border-radius:8px;margin-bottom:12px;display:block">
                                @endif

                                {{--
                                    Isi caption sudah HTML siap kirim yang
                                    sepenuhnya disusun server — judul dan
                                    tautannya di-escape di ChannelPostService.
                                    Ditampilkan mentah supaya admin melihat
                                    tautannya persis seperti di Telegram.
                                --}}
                                <div style="white-space:pre-wrap;line-height:1.7">{!! $teks !!}</div>
                            </div>
                        </div>
                    @endforeach

                    @php
                        $belumSiap = $episodes->filter(fn ($e) => ! ($e->video?->isSyncedToTelegram() ?? false));
                    @endphp

                    @if ($belumSiap->isNotEmpty())
                        <p class="queue-error">
                            {{ $belumSiap->count() }} episode belum tersinkron ke Telegram, jadi barisnya
                            ditulis tanpa tautan: episode
                            {{ $belumSiap->pluck('episode_number')->take(15)->join(', ') }}@if ($belumSiap->count() > 15), …@endif.
                            Sinkronkan dulu di menu Sinkron Telegram bila ingin semuanya bisa ditekan.
                        </p>
                    @endif

                    {{-- data-confirm menempel di FORM, bukan tombol: penangan
                         di resources/js/admin.js mendengarkan event submit.
                         Judul dan pesannya dibaca dari data-confirm-title dan
                         data-confirm-message. --}}
                    <form method="POST" action="{{ route('admin.channel-post.send') }}"
                          style="margin-top:16px"
                          data-confirm
                          data-confirm-title="Kirim ke channel?"
                          data-confirm-message="{{ $episodes->count() }} episode {{ $drama->title }} akan diposting ke channel dan langsung terlihat semua pelanggan. Postingan tidak bisa ditarik dari panel.">
                        @csrf
                        <input type="hidden" name="drama_id" value="{{ $drama->id }}">
                        <input type="hidden" name="from" value="{{ $dari }}">
                        <input type="hidden" name="to" value="{{ $sampai }}">

                        <button type="submit" class="btn btn-primary" @disabled($penghalang !== null)>
                            <x-web.home.icon name="send" :size="14" />
                            Kirim ke channel
                        </button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="panel-head">
            <h2>Riwayat kiriman</h2>
            <span class="panel-meta">20 terakhir</span>
        </div>

        @if ($riwayat->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Belum pernah mengirim apa pun ke channel.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Drama</th>
                            <th>Rentang</th>
                            <th>Episode</th>
                            <th>Sumber</th>
                            <th>Oleh</th>
                            <th>Status</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($riwayat as $r)
                            <tr>
                                <td>{{ $r->drama?->title ?? '—' }}</td>
                                <td>{{ $r->rentang() }}</td>
                                <td>{{ number_format($r->episode_count) }}</td>
                                <td>
                                    <span class="badge {{ $r->source === 'auto' ? 'badge-pending' : '' }}">
                                        {{ $r->source === 'auto' ? 'otomatis' : 'manual' }}
                                    </span>
                                </td>
                                <td>{{ $r->sender?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $r->berhasil() ? 'badge-on' : 'badge-off' }}">
                                        {{ $r->berhasil() ? 'Terkirim' : 'Gagal' }}
                                    </span>
                                    @if (! $r->berhasil() && $r->error)
                                        <br><span class="queue-error">{{ Str::limit($r->error, 120) }}</span>
                                    @endif
                                </td>
                                <td>{{ $r->created_at?->ringkas() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

@endsection
