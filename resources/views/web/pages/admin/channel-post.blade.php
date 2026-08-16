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
                                @if ($teks === '')
                                    {{-- Potongan pertama kosong berarti caption
                                         sengaja ditiadakan: kepala postingan tidak
                                         muat di batas 1024 walau sinopsisnya sudah
                                         dipangkas, jadi fotonya dikirim sendirian
                                         dan seluruh teksnya menyusul di bawah. --}}
                                    <p class="page-subtitle">
                                        Tanpa caption — template ini terlalu panjang untuk caption foto
                                        (batas 1024 karakter), jadi posternya dikirim sendirian dan seluruh
                                        teksnya menyusul sebagai pesan di bawahnya. Persingkat template di
                                        Pengaturan → Channel Telegram bila ingin captionnya kembali menempel
                                        di poster.
                                    </p>
                                @else
                                    <div style="white-space:pre-wrap;line-height:1.7">{!! $teks !!}</div>
                                @endif
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

    {{--
        Pengumuman bebas ke channel.
        Panelnya menumpang di halaman ini karena tujuannya sama — channel yang
        sama, pembaca yang sama — tapi seluruh aksinya milik
        ChannelAnnouncementController, dan tidak satu pun menyentuh jalur
        kiriman katalog di atas.
    --}}
    <section class="panel">
        <div class="panel-head">
            <h2>Pengumuman ke channel</h2>
            <span class="panel-meta">tulisan bebas, bukan katalog drama</span>
        </div>

        <div class="detail-body-admin">
            <p class="page-subtitle">
                Untuk kabar yang bukan drama: jadwal rilis, pemberitahuan gangguan,
                promo VIP. Tanpa gambar, batasnya 4096 karakter. Dengan gambar, tulisan
                jadi caption foto yang batasnya 1024 — kalau lewat, fotonya dikirim
                sendirian dan tulisannya menyusul sebagai pesan di bawahnya, bukan
                dipotong diam-diam.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.channel-announcement.store') }}"
              class="admin-form" enctype="multipart/form-data"
              data-confirm
              data-confirm-title="Kirim pengumuman?"
              data-confirm-ok="Ya, kirim"
              data-confirm-message="Pengumuman ini akan tayang di channel dan langsung terbaca semua pelanggan. Postingan tidak bisa ditarik dari panel."
              data-pengumuman-form>
            @csrf

            <x-admin.field name="body" label="Isi pengumuman" type="textarea" :rows="6" required
                           data-pengumuman-teks
                           hint="Mendukung HTML sederhana: <b>, <i>, <a href>, <blockquote>." />

            <p class="field-hint" data-pengumuman-hitung></p>

            <div class="field">
                <label for="field-image_file">Gambar (opsional)</label>
                {{-- Ditulis tangan, tidak lewat x-admin.field: komponen itu
                     menempelkan value= pada input, dan input berkas tidak
                     boleh punya nilai awal. --}}
                <input type="file" id="field-image_file" name="image_file" class="control"
                       accept="image/jpeg,image/png,image/webp" data-pengumuman-gambar>
                <p class="field-hint">{{ \App\Services\Admin\MediaService::hint() }}</p>
                @error('image_file')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label>Tombol tautan (opsional)</label>

                @for ($i = 0; $i < $maxTombol; $i++)
                    <div class="admin-toolbar" style="margin-bottom:6px">
                        <input type="text" name="buttons[{{ $i }}][label]"
                               value="{{ old('buttons.'.$i.'.label') }}"
                               class="control control-sm" maxlength="64"
                               placeholder="Label tombol {{ $i + 1 }}" style="max-width:220px">

                        <input type="url" name="buttons[{{ $i }}][url]"
                               value="{{ old('buttons.'.$i.'.url') }}"
                               class="control control-sm" maxlength="255"
                               placeholder="https://t.me/… atau https://…">
                    </div>
                @endfor

                <p class="field-hint">
                    Baris yang dikosongkan diabaikan. URL harus diawali https://, http://,
                    atau tg:// — Telegram menolak seluruh pesannya kalau tidak.
                </p>

                @error('buttons')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label>Waktu tayang</label>

                <label class="checkbox-item">
                    <input type="radio" name="kirim" value="sekarang"
                           @checked(old('kirim', 'sekarang') === 'sekarang') data-pengumuman-mode>
                    Kirim sekarang
                </label>

                <label class="checkbox-item">
                    <input type="radio" name="kirim" value="jadwal"
                           @checked(old('kirim') === 'jadwal') data-pengumuman-mode>
                    Jadwalkan
                </label>

                {{-- Nilai dan batas bawahnya ditulis dalam waktu LOKAL, karena
                     itu yang dibaca dan diketik admin. Pengubahannya ke UTC
                     terjadi di controller, satu tempat saja. --}}
                <input type="datetime-local" name="scheduled_at" class="control"
                       value="{{ old('scheduled_at') }}"
                       min="{{ \App\Support\Waktu::lokal(now())?->format('Y-m-d\TH:i') }}"
                       data-pengumuman-jadwal style="max-width:260px;margin-top:8px">

                <p class="field-hint">
                    Waktu {{ \App\Support\Waktu::label() }}. Pengumuman terjadwal dipungut
                    penjadwal tiap menit, jadi bisa meleset paling banyak satu menit —
                    dan bisa dibatalkan kapan saja selama belum tayang.
                </p>

                @error('scheduled_at')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" @disabled($penghalangPengumuman !== null)>
                    <x-web.home.icon name="send" :size="14" />
                    <span data-pengumuman-tombol>Kirim pengumuman</span>
                </button>

                @if ($penghalangPengumuman)
                    <span class="queue-error">{{ $penghalangPengumuman }}</span>
                @endif
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Riwayat pengumuman</h2>
            <span class="panel-meta">15 terakhir</span>
        </div>

        @if ($pengumuman->isEmpty())
            <div class="detail-body-admin">
                <p class="page-subtitle">Belum ada pengumuman.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Isi</th>
                            <th>Tayang</th>
                            <th>Oleh</th>
                            <th>Status</th>
                            <th class="col-actions">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pengumuman as $p)
                            <tr>
                                <td>
                                    {{-- strip_tags: isinya HTML siap kirim, dan
                                         menampilkannya mentah di tabel berarti
                                         tabelnya ikut menuruti tag di dalamnya. --}}
                                    {{ Str::limit(strip_tags($p->body), 90) }}

                                    @if ($p->image)
                                        <br><span class="cell-empty">+ gambar</span>
                                    @endif

                                    @if ($p->buttons)
                                        <br><span class="cell-empty">{{ count($p->buttons) }} tombol</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($p->berhasil())
                                        {{ \App\Support\Waktu::ringkas($p->sent_at) }}
                                    @elseif ($p->scheduled_at)
                                        {{ \App\Support\Waktu::ringkas($p->scheduled_at) }}
                                    @else
                                        <span class="cell-empty">—</span>
                                    @endif
                                </td>
                                <td>{{ $p->author?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $p->statusBadge() }}">{{ $p->statusLabel() }}</span>

                                    @if ($p->error)
                                        <br><span class="queue-error">{{ Str::limit($p->error, 120) }}</span>
                                    @endif
                                </td>
                                <td class="col-actions">
                                    @if ($p->bisaDibatalkan())
                                        <form method="POST"
                                              action="{{ route('admin.channel-announcement.cancel', $p->id) }}"
                                              class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <x-web.home.icon name="close" :size="14" /> Batalkan
                                            </button>
                                        </form>
                                    @elseif (! $p->berhasil())
                                        <form method="POST"
                                              action="{{ route('admin.channel-announcement.resend', $p->id) }}"
                                              class="inline-form"
                                              data-confirm
                                              data-confirm-title="Kirim ulang?"
                                              data-confirm-ok="Kirim ulang"
                                              data-confirm-message="Pengumuman ini akan tayang di channel sekarang juga.">
                                            @csrf
                                            <button type="submit" class="btn btn-sm"
                                                    @disabled($penghalangPengumuman !== null)>
                                                <x-web.home.icon name="restore" :size="14" /> Kirim ulang
                                            </button>
                                        </form>
                                    @else
                                        <span class="cell-empty">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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
\n
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const form = document.querySelector('[data-pengumuman-form]');

        if (!form) return;

        const teks   = form.querySelector('[data-pengumuman-teks]');
        const gambar = form.querySelector('[data-pengumuman-gambar]');
        const jadwal = form.querySelector('[data-pengumuman-jadwal]');
        const label  = form.querySelector('[data-pengumuman-tombol]');
        const info   = form.querySelector('[data-pengumuman-hitung]');
        const mode   = () => form.querySelector('[data-pengumuman-mode]:checked')?.value || 'sekarang';

        const BATAS_CAPTION = {{ $batasCaption }};
        const BATAS_TEKS    = 4000;

        /*
        | Hitungan di sini SENGAJA kasar — panjang mentah, bukan panjang
        | terlihat versi Telegram yang membuang tag HTML dan URL di dalam
        | href. Angka pastinya dihitung server saat mengirim; yang dibutuhkan
        | di sini cuma peringatan dini bahwa tulisannya mulai kepanjangan.
        | Hitungan kasar yang lebih besar dari yang sebenarnya aman: ia
        | memperingatkan sedikit terlalu awal, tidak terlambat.
        */
        const hitung = () => {
            const panjang = teks.value.length;
            const pakaiGambar = gambar.files && gambar.files.length > 0;
            const batas = pakaiGambar ? BATAS_CAPTION : BATAS_TEKS;

            let pesan = panjang + ' karakter (kira-kira). Batas '
                + (pakaiGambar ? 'caption foto ' : 'pesan teks ') + batas + '.';

            if (pakaiGambar && panjang > batas) {
                pesan += ' Terlalu panjang untuk caption — fotonya akan dikirim sendirian'
                    + ' dan tulisannya menyusul sebagai pesan di bawahnya.';
            }

            info.textContent = pesan;
        };

        // Tombol dan kalimat konfirmasi mengikuti mode: dialog yang berkata
        // "akan tayang sekarang" pada pengumuman terjadwal adalah dialog yang
        // menjawab pertanyaan yang tidak ditanyakan.
        const ikutiMode = () => {
            const terjadwal = mode() === 'jadwal';

            jadwal.disabled = !terjadwal;
            label.textContent = terjadwal ? 'Jadwalkan pengumuman' : 'Kirim pengumuman';

            form.dataset.confirmTitle = terjadwal ? 'Jadwalkan pengumuman?' : 'Kirim pengumuman?';
            form.dataset.confirmOk    = terjadwal ? 'Jadwalkan' : 'Ya, kirim';
            form.dataset.confirmMessage = terjadwal
                ? 'Pengumuman disimpan dan tayang otomatis pada waktu yang dipilih. Selama belum tayang, ia masih bisa dibatalkan.'
                : 'Pengumuman ini akan tayang di channel dan langsung terbaca semua pelanggan. Postingan tidak bisa ditarik dari panel.';
        };

        teks.addEventListener('input', hitung);
        gambar.addEventListener('change', hitung);

        form.querySelectorAll('[data-pengumuman-mode]').forEach(
            (el) => el.addEventListener('change', ikutiMode)
        );

        hitung();
        ikutiMode();
    });
</script>
@endpush
