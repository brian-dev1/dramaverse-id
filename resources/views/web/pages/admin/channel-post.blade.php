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
            <h2>Pilih drama dan rentang part</h2>
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
                       class="control control-sm" placeholder="Dari part" style="max-width:130px">

                <input type="number" name="to" value="{{ $sampai }}" min="1"
                       class="control control-sm" placeholder="Sampai part" style="max-width:140px">

                <button type="submit" class="btn btn-sm">
                    <x-web.home.icon name="search" :size="14" /> Pratinjau
                </button>
            </form>

            <span class="panel-meta">Kosongkan rentang untuk mengirim seluruh part.</span>
        </div>
    </section>

    @if ($drama)

        <section class="panel">
            <div class="panel-head">
                <h2>Pratinjau</h2>
                <span class="panel-meta">
                    {{ $episodes->count() }} part
                    @if (count($potongan) > 1)
                        · akan dikirim sebagai {{ count($potongan) }} pesan
                    @endif
                </span>
            </div>

            <div class="detail-body-admin">

                @if ($episodes->isEmpty())
                    <p class="page-subtitle">Tidak ada part pada rentang itu.</p>
                @else

                    @if (count($potongan) > 1)
                        {{-- Bukan peringatan kesalahan, melainkan penjelasan.
                             Caption foto Telegram maksimal 1024 karakter, jadi
                             daftar yang panjang memang harus dipecah. --}}
                        <p class="page-subtitle">
                            Daftarnya melewati batas caption foto Telegram (1024 karakter
                            yang terlihat — tag HTML dan URL tidak dihitung), jadi sisanya
                            menyusul sebagai pesan teks di bawah posternya.
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
                                {{-- Angka dari ChannelPostService, sama persis
                                     dengan yang dipakainya saat memutuskan
                                     apakah postingan perlu dipecah. --}}
                                <span class="panel-meta">
                                    {{ $panjang[$i] }} karakter
                                    @if ($i === 0)
                                        / {{ $batasCaption }}
                                    @endif
                                </span>
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
                            {{ $belumSiap->count() }} part belum tersinkron ke Telegram, jadi barisnya
                            ditulis tanpa tautan: part
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
                          data-confirm-ok="Kirim sekarang"
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

    {{--
        Panel kirim massal. Berdiri sendiri di bawah panel satuan, tidak
        menggantikannya: pratinjau per drama tetap satu-satunya cara melihat
        caption sebelum terkirim, dan untuk drama tertentu itu masih yang
        paling benar. Yang di bawah ini untuk pekerjaan lain — menaruh
        katalog lama ke channel yang baru dibuat, misalnya, di mana yang
        dibutuhkan bukan ketelitian per caption melainkan tidak menekan
        tombol yang sama enam puluh kali.
    --}}
    <section class="panel">
        <div class="panel-head">
            <h2>Kirim banyak drama sekaligus</h2>
            <span class="panel-meta">maksimal {{ $bulkMax }} drama sekali kirim</span>
        </div>

        <div class="detail-body-admin">
            <p class="page-subtitle">
                Centang dramanya, lalu Kirim. Semuanya lewat antrean dengan jeda
                {{ $bulkJeda }} detik antar drama — Telegram membatasi sekitar 20 pesan
                per menit ke satu channel, dan mengirim serentak akan ditolaknya.
                Postingannya karena itu tidak muncul sekaligus. Rentang part tidak
                berlaku di sini; tiap drama dikirim seluruh partnya.
                <br>
                Hasil tiap drama muncul di tabel Riwayat di bawah, satu per satu.
                <br>
                Part yang belum tersinkron ke Telegram tetap ditulis barisnya, tapi
                tanpa tautan — sama seperti kiriman satuan. Tidak ada pratinjau di
                sini, jadi sinkronkan dulu bila ingin semua barisnya bisa ditekan.
            </p>
        </div>

        @if ($dramas->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Belum ada drama.</p>
            </div>
        @else

            <div class="admin-toolbar">
                <input type="search" class="control control-sm" data-cp-cari
                       placeholder="Saring judul…" style="max-width:260px" autocomplete="off">
                <span class="panel-meta" data-cp-tampil></span>
            </div>

            {{-- Tabelnya SENGAJA tidak dilingkupi form pengirimnya. Sebuah
                 form di dalam form tidak sah di HTML dan parser membuang yang
                 bersarang; kotak centangnya menempel lewat atribut form=,
                 pola yang sama dengan halaman Sinkron Telegram. --}}
            <div class="table-wrap" style="max-height:420px;overflow:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check">
                                {{-- Hanya mencentang yang BELUM pernah dikirim.
                                     Yang sudah ada di channel dicentang manual,
                                     satu per satu, supaya postingan kembar
                                     selalu keputusan yang disengaja. --}}
                                <input type="checkbox" data-cp-all
                                       title="Centang semua yang belum pernah dikirim">
                            </th>
                            <th>Drama</th>
                            <th>Total part</th>
                            <th>Status channel</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dramas as $d)
                            @php $terkirim = isset($sudahDikirim[$d->id]); @endphp
                            <tr data-cp-baris data-judul="{{ Str::lower($d->title) }}">
                                <td class="col-check">
                                    <input type="checkbox" form="channel-bulk-form" name="ids[]"
                                           value="{{ $d->id }}" data-cp-item
                                           data-terkirim="{{ $terkirim ? '1' : '0' }}">
                                </td>
                                <td>{{ $d->title }}</td>
                                <td>
                                    @if ($d->total_episode)
                                        {{ number_format($d->total_episode) }}
                                    @else
                                        <span class="cell-empty">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($terkirim)
                                        <span class="badge badge-on">Sudah dikirim</span>
                                    @else
                                        <span class="cell-empty">Belum pernah</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('admin.channel-post.bulk') }}"
                  id="channel-bulk-form" class="bulk-bar"
                  data-confirm
                  data-confirm-title="Kirim ke channel?"
                  data-confirm-ok="Antrekan sekarang"
                  data-confirm-message="Drama yang dicentang akan diposting ke channel dan langsung terlihat semua pelanggan. Postingan tidak bisa ditarik dari panel.">
                @csrf

                <span class="panel-meta">
                    <span data-cp-count>Belum ada yang dicentang.</span>
                </span>

                <button type="button" class="btn btn-sm btn-ghost" data-cp-none>
                    Bersihkan
                </button>

                <label class="panel-meta" style="display:inline-flex;align-items:center;gap:6px">
                    {{-- Bawaannya melewati yang sudah pernah dikirim.
                         Dicentang bila channelnya memang sudah dibersihkan
                         manual dan katalognya perlu ditaruh ulang. --}}
                    <input type="checkbox" name="ulangi" value="1">
                    Kirim ulang yang sudah pernah dikirim
                </label>

                <button type="submit" class="btn btn-primary" @disabled($penghalang !== null)>
                    <x-web.home.icon name="send" :size="14" />
                    Kirim yang dicentang
                </button>
            </form>
        @endif
    </section>

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
                            <th>Part</th>
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

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const semua = document.querySelector('[data-cp-all]');
        const items = Array.from(document.querySelectorAll('[data-cp-item]'));

        if (!semua || !items.length) {
            return;
        }

        const penghitung = document.querySelector('[data-cp-count]');
        const tombolBersih = document.querySelector('[data-cp-none]');
        const kotakCari  = document.querySelector('[data-cp-cari]');
        const infoTampil = document.querySelector('[data-cp-tampil]');
        const baris      = Array.from(document.querySelectorAll('[data-cp-baris]'));

        const belumDikirim = items.filter((el) => el.dataset.terkirim !== '1');

        const hitung = () => {
            const dipilih = items.filter((el) => el.checked);

            const ulang = dipilih.filter((el) => el.dataset.terkirim === '1').length;

            if (!dipilih.length) {
                penghitung.textContent = 'Belum ada yang dicentang.';
            } else {
                let teks = `${dipilih.length} dicentang`;

                // Yang sudah pernah dikirim disebut terpisah: tanpa mencentang
                // "kirim ulang" ia akan dilewati, dan angka yang benar-benar
                // terkirim jadi lebih kecil dari yang terlihat di sini.
                if (ulang > 0) {
                    teks += ` — ${ulang} di antaranya sudah pernah dikirim`;
                }

                penghitung.textContent = teks + '.';
            }

            const tercentang = belumDikirim.filter((el) => el.checked).length;

            semua.checked = belumDikirim.length > 0 && tercentang === belumDikirim.length;

            semua.indeterminate = tercentang > 0 && tercentang < belumDikirim.length;
        };

        semua.addEventListener('change', () => {
            const nyalakan = semua.checked;

            // Hanya baris yang sedang terlihat. Mencentang sekaligus baris
            // yang tersaring keluar berarti mengirim drama yang tidak pernah
            // dilihat admin di layarnya.
            belumDikirim
                .filter((el) => !el.closest('[data-cp-baris]').hidden)
                .forEach((el) => { el.checked = nyalakan; });

            hitung();
        });

        items.forEach((el) => el.addEventListener('change', hitung));

        tombolBersih?.addEventListener('click', () => {
            items.forEach((el) => { el.checked = false; });
            hitung();
        });

        if (kotakCari) {
            const saring = () => {
                const kata = kotakCari.value.trim().toLowerCase();

                let terlihat = 0;

                baris.forEach((tr) => {
                    const cocok = kata === '' || tr.dataset.judul.includes(kata);

                    tr.hidden = !cocok;

                    if (cocok) terlihat++;
                });

                infoTampil.textContent = kata === ''
                    ? ''
                    : `${terlihat} dari ${baris.length} judul.`;
            };

            kotakCari.addEventListener('input', saring);
        }

        // Centangan bertahan saat disaring — kotak yang tersembunyi tetap
        // ikut terkirim. Itu disengaja: admin bisa mencentang lewat beberapa
        // kali pencarian, dan penghitung di atas selalu menyebut totalnya.
        hitung();
    });
</script>
@endpush
