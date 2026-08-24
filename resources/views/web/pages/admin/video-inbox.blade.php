@extends('web.layouts.admin')

@section('title', 'Video Inbox')

@section('content')

    <style>
        .inbox-wrap { max-width: 980px; }

        .inbox-intro {
            margin: 0 0 18px;
            font-size: 13px;
            line-height: 1.6;
            opacity: .65;
        }

        .inbox-alert {
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            border: 1px solid rgba(255,255,255,.12);
        }

        .inbox-skipped {
            margin: 8px 0 0;
            padding-left: 18px;
            font-size: 12.5px;
            line-height: 1.7;
            opacity: .8;
        }

        .inbox-tabs {
            display: flex;
            gap: 8px;
            margin: 0 0 14px;
            flex-wrap: wrap;
        }

        .inbox-tab {
            padding: 6px 12px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 999px;
            font-size: 12.5px;
            text-decoration: none;
            color: inherit;
            opacity: .7;
        }

        .inbox-tab.is-on {
            opacity: 1;
            border-color: rgba(255,255,255,.4);
            background: rgba(255,255,255,.06);
        }

        /* --- Panel kendali --- */

        .inbox-panel {
            position: sticky;
            top: 0;
            z-index: 5;
            margin: 0 0 16px;
            padding: 12px 16px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 10px;
            background: #1a0f0c;
            box-shadow: 0 6px 18px rgba(0,0,0,.35);
        }

        .inbox-panel-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .inbox-count {
            flex: 1 1 100%;
            margin: 10px 0 0;
            font-size: 12.5px;
            opacity: .7;
        }

        /* --- Daftar video --- */

        .inbox-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .inbox-row {
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 10px;
            padding: 14px 16px;
            background: rgba(255,255,255,.015);
        }

        .inbox-row.is-picked {
            border-color: rgba(255,255,255,.30);
            background: rgba(255,255,255,.045);
        }

        .inbox-row.is-clash {
            border-color: #e5484d;
        }

        .inbox-head {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            flex-wrap: wrap;
        }

        .inbox-pick {
            flex: 0 0 auto;
            margin-top: 2px;
        }

        .inbox-pick input { width: 16px; height: 16px; }

        .inbox-name {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.3;
            word-break: break-all;
            flex: 1 1 260px;
        }

        .inbox-name label { cursor: pointer; }

        .inbox-tag {
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.18);
            opacity: .8;
            white-space: nowrap;
        }

        .inbox-meta {
            margin: 8px 0 0;
            font-size: 12px;
            line-height: 1.6;
            opacity: .55;
            word-break: break-all;
        }

        .inbox-meta span + span::before {
            content: "·";
            margin: 0 7px;
            opacity: .6;
        }

        .inbox-assigned {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 12px 0 0;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.08);
            font-size: 12.5px;
        }

        .inbox-assigned p {
            margin: 0;
            flex: 1 1 220px;
            opacity: .7;
        }

        /* Kotak pindah part, tersembunyi sampai tombolnya ditekan. Dibuka
           per baris, bukan sekaligus semuanya: memindahkan itu tindakan pada
           satu video, dan lima kotak terbuka bersamaan hanya membuat halaman
           terlihat seperti daftar kerja yang belum selesai. */
        .inbox-move {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin: 12px 0 0;
            padding: 12px;
            border: 1px dashed rgba(255,255,255,.18);
            border-radius: 8px;
        }

        .inbox-move .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1 1 200px;
            min-width: 170px;
            margin: 0;
        }

        .inbox-move label {
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .55;
        }

        .inbox-move .control { width: 100%; }

        .inbox-move-note {
            flex: 1 1 100%;
            margin: 0;
            font-size: 12px;
            opacity: .6;
        }

        .inbox-form {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.08);
        }

        .inbox-form .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1 1 220px;
            min-width: 180px;
            margin: 0;
        }

        .inbox-form label {
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .55;
        }

        .inbox-form .control { width: 100%; }

        .inbox-note {
            flex: 1 1 100%;
            margin: 0;
            font-size: 12px;
        }

        .inbox-key {
            margin: 6px 0 0;
            font-size: 11.5px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            opacity: .4;
            word-break: break-all;
        }

        .inbox-pager {
            margin-top: 18px;
            display: flex;
            justify-content: center;
        }
    </style>

    <div class="inbox-wrap">

        <p class="inbox-intro">
            @if ($tampil === 'terpasang')
                Video yang sudah dipasang ke part. Salah drama atau salah part? Tekan
                <strong>Pindahkan</strong> untuk memilih tujuan yang benar dalam satu
                langkah — part lama otomatis kosong. Atau tekan
                <strong>Lepas dari part ini</strong> bila ingin videonya kembali dulu ke
                tab Belum terpasang. Berkasnya tetap utuh di storage provider, tidak ada
                yang diunduh atau diunggah ulang.
            @else
                Video di halaman ini sudah tersimpan di storage provider lewat worker
                Telegram. Pilih drama dan part untuk tiap video — boleh beda-beda
                drama — lalu pasang semuanya sekali tekan di panel atas. Tidak ada
                berkas yang diunduh atau diunggah ulang.
            @endif
        </p>

        {{-- Yang terpasang tidak lagi ikut menumpuk di daftar kerja, tapi juga
             tidak dihapus dari pandangan: ia catatan berkas mana jadi part
             mana, dan itu justru dicari saat ada yang salah. --}}
        @if ($tampil !== 'terpasang' && $dramas->isEmpty() && $videos->isNotEmpty())
            <div class="inbox-alert">
                Tidak ada drama yang masih punya part kosong, jadi daftar pilihan dramanya
                kosong. Buat partnya dulu lewat tombol <strong>+ Part</strong>, lalu muat
                ulang halaman ini.
            </div>
        @endif

        <div class="inbox-tabs">
            <a href="{{ route('admin.video-inbox.index') }}"
               class="inbox-tab {{ $tampil === 'tersedia' ? 'is-on' : '' }}">
                Belum terpasang ({{ number_format($jumlah['tersedia']) }})
            </a>

            <a href="{{ route('admin.video-inbox.index', ['tampil' => 'terpasang']) }}"
               class="inbox-tab {{ $tampil === 'terpasang' ? 'is-on' : '' }}">
                Sudah terpasang ({{ number_format($jumlah['terpasang']) }})
            </a>
        </div>

        @if (session('success'))
            <div class="inbox-alert">
                {{ session('success') }}

                @if (session('dilewati'))
                    <ul class="inbox-skipped">
                        @foreach (session('dilewati') as $alasan)
                            <li>{{ $alasan }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if (session('error'))
            <div class="inbox-alert field-error">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="inbox-alert field-error">
                <ul class="inbox-skipped">
                    @foreach ($errors->all() as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.video-inbox.assign') }}"
              data-inbox-form
              data-episodes-url="{{ route('admin.episode.video.episodes', ['drama' => 0]) }}"
              data-old-pairs="{{ json_encode(old('pairs', new stdClass)) }}">

            @csrf

            <div class="inbox-panel" @if ($tampil === 'terpasang') hidden @endif>

                <div class="inbox-panel-actions">

                    <button type="button" class="btn btn-ghost btn-sm" data-pick-all>
                        Centang semua
                    </button>

                    <button type="button" class="btn btn-ghost btn-sm" data-pick-none>
                        Bersihkan
                    </button>

                    <a href="{{ route('admin.episode.batch') }}" class="btn btn-ghost btn-sm">
                        + Part
                    </a>

                    <button type="submit" class="btn btn-primary btn-sm" data-inbox-submit disabled>
                        Pasang video terpilih
                    </button>

                    <p class="inbox-count" data-inbox-count>Belum ada video dicentang.</p>

                </div>

            </div>

            <div class="inbox-list">

                @forelse ($videos as $video)

                    @php
                        $bisaDipasang = $video->isAvailable() && filled($video->checksum);
                    @endphp

                    <section class="inbox-row"
                             data-inbox-row
                             data-video-id="{{ $video->id }}"
                             data-filename="{{ $video->original_filename }}">

                        <div class="inbox-head">

                            @if ($bisaDipasang)
                                <span class="inbox-pick">
                                    <input type="checkbox"
                                           id="inbox-pick-{{ $video->id }}"
                                           name="pairs[{{ $video->id }}][video_id]"
                                           value="{{ $video->id }}"
                                           data-inbox-check>
                                </span>
                            @endif

                            <h2 class="inbox-name">
                                @if ($bisaDipasang)
                                    <label for="inbox-pick-{{ $video->id }}">
                                        {{ $video->original_filename }}
                                    </label>
                                @else
                                    {{ $video->original_filename }}
                                @endif
                            </h2>

                            <span class="inbox-tag">
                                {{ $video->status === 'available' ? 'Tersedia' : 'Terpasang' }}
                            </span>

                        </div>

                        <p class="inbox-meta">
                            <span>{{ number_format($video->size / 1024 / 1024, 1) }} MB</span>
                            <span>{{ $video->provider?->name ?? 'Provider tidak ditemukan' }}</span>
                            <span>{{ \App\Support\Waktu::ringkas($video->uploaded_at) }}</span>
                            <span>Checksum {{ $video->checksum ? 'tersedia' : 'belum ada' }}</span>
                        </p>

                        <p class="inbox-key">{{ $video->object_key }}</p>

                        @if (! $video->isAvailable())

                            {{-- Tombolnya berada di LUAR form pemasangan (lihat
                                 kumpulan form di bawah daftar) dan ditarik ke sini
                                 lewat atribut `form`. Form di dalam form bukan HTML
                                 yang sah: peramban membuang yang di dalam, dan
                                 tombolnya akan ikut mengirim form pemasangan. --}}
                            <div class="inbox-assigned">

                                @if ($video->episode)
                                    <p>
                                        Terpasang ke
                                        <strong>
                                            {{ $video->episode->drama?->title ?? 'Drama' }}
                                            — Part {{ $video->episode->episode_number }}
                                        </strong>
                                    </p>
                                @else
                                    <p>Tidak lagi tersedia untuk dipasang.</p>
                                @endif

                                @if ($video->episode)
                                    <button type="button"
                                            class="btn btn-ghost btn-sm"
                                            data-move-toggle
                                            aria-expanded="false">
                                        Pindahkan
                                    </button>
                                @endif

                                <button type="submit"
                                        class="btn btn-ghost btn-sm"
                                        form="inbox-release-{{ $video->id }}">
                                    Lepas dari part ini
                                </button>

                            </div>

                            {{--
                                Kotak pindah part.

                                Kedua dropdown-nya berada di dalam form
                                pemasangan (lihat <form data-inbox-form> di
                                atas), jadi yang harus ikut terkirim ditarik
                                keluar lewat atribut `form`. Dropdown drama
                                sengaja TIDAK diberi `name`: ia cuma alat untuk
                                menyaring daftar part, dan yang menentukan
                                tujuan hanyalah episodenya.
                            --}}
                            @if ($video->episode)
                                <div class="inbox-move" data-move-box hidden>

                                    @if ($dramas->isEmpty())

                                        <p class="inbox-move-note field-error">
                                            Tidak ada drama yang masih punya part kosong,
                                            jadi tidak ada tujuan yang bisa dipilih. Buat
                                            partnya dulu lewat <strong>+ Part</strong>.
                                        </p>

                                    @else

                                        <div class="field">
                                            <label for="move-drama-{{ $video->id }}">Drama tujuan</label>

                                            <select id="move-drama-{{ $video->id }}"
                                                    class="control"
                                                    data-move-drama>
                                                <option value="">— pilih drama —</option>
                                                @foreach ($dramas as $drama)
                                                    <option value="{{ $drama->id }}"
                                                        @selected($video->episode->drama_id == $drama->id)>
                                                        {{ $drama->title }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="field">
                                            <label for="move-episode-{{ $video->id }}">Part tujuan</label>

                                            <select id="move-episode-{{ $video->id }}"
                                                    name="episode_id"
                                                    form="inbox-move-{{ $video->id }}"
                                                    class="control"
                                                    data-move-episode
                                                    disabled>
                                                <option value="">— pilih drama dulu —</option>
                                            </select>
                                        </div>

                                        <button type="submit"
                                                class="btn btn-primary btn-sm"
                                                form="inbox-move-{{ $video->id }}"
                                                data-move-submit
                                                disabled>
                                            Pindahkan ke sini
                                        </button>

                                        <p class="inbox-move-note">
                                            Part yang sudah punya video tidak bisa dipilih —
                                            lepas dulu isinya. Berkasnya tidak berpindah di storage.
                                        </p>

                                    @endif

                                </div>
                            @endif

                        @elseif ($bisaDipasang)

                            <div class="inbox-form">

                                <div class="field">
                                    <label for="inbox-drama-{{ $video->id }}">Drama</label>

                                    <select id="inbox-drama-{{ $video->id }}"
                                            class="control"
                                            data-inbox-drama>

                                        <option value="">— pilih drama —</option>
                                        {{-- Yang tampil hanya drama yang masih punya
                                             part kosong; lihat VideoInboxController. --}}

                                        @foreach ($dramas as $drama)
                                            <option value="{{ $drama->id }}">{{ $drama->title }}</option>
                                        @endforeach

                                    </select>

                                    {{--
                                        Dropdown drama sendiri tidak dikirim ke server — yang
                                        menentukan tujuan hanyalah episode. Nilainya tetap
                                        dititipkan lewat input tersembunyi ini supaya pilihan
                                        drama bisa dipulihkan apa adanya kalau permintaannya
                                        ditolak, tanpa perlu menebaknya balik dari episode.
                                    --}}
                                    <input type="hidden"
                                           name="pairs[{{ $video->id }}][drama_id]"
                                           data-inbox-drama-id
                                           disabled>
                                </div>

                                <div class="field">
                                    <label for="inbox-episode-{{ $video->id }}">Part</label>

                                    <select id="inbox-episode-{{ $video->id }}"
                                            name="pairs[{{ $video->id }}][episode_id]"
                                            class="control"
                                            data-inbox-episode
                                            disabled>

                                        <option value="">— pilih drama dulu —</option>

                                    </select>
                                </div>

                                <p class="inbox-note field-error" data-inbox-clash hidden>
                                    Part ini juga dipilih video lain.
                                </p>

                            </div>

                        @else

                            {{-- Sisanya: masih tersedia, tetapi checksumnya kosong. --}}
                            <p class="inbox-note field-error">
                                Belum ada checksum SHA-256, video belum dapat dipasang.
                            </p>

                        @endif

                    </section>

                @empty

                    <section class="inbox-row">
                        @if ($tampil === 'terpasang')
                            <h2 class="inbox-name">Belum ada yang terpasang</h2>
                            <p class="inbox-meta"><span>Video yang sudah dipasang ke part akan muncul di sini.</span></p>
                        @elseif ($jumlah['terpasang'] > 0)
                            <h2 class="inbox-name">Semua video sudah terpasang</h2>
                            <p class="inbox-meta">
                                <span>Tidak ada yang menunggu dikerjakan.</span>
                                <span>{{ number_format($jumlah['terpasang']) }} video ada di tab Sudah terpasang.</span>
                            </p>
                        @else
                            <h2 class="inbox-name">Inbox kosong</h2>
                            <p class="inbox-meta"><span>Belum ada video dari worker Telegram.</span></p>
                        @endif
                    </section>

                @endforelse

            </div>

        </form>

        {{--
            Form pelepasan, satu per video terpasang.

            Sengaja dikumpulkan di sini alih-alih ditaruh di dalam barisnya:
            baris-baris itu berada di dalam form pemasangan, dan form bersarang
            akan dibuang peramban. Tombolnya di baris masing-masing menunjuk ke
            sini lewat atribut `form`.

            `data-confirm` memunculkan dialog konfirmasi yang sama dengan
            halaman admin lain (admin.js). Tombolnya tidak merah karena tidak
            ada yang dihapus: berkasnya tetap di storage, dan pemasangannya
            bisa diulang kapan saja.
        --}}
        @foreach ($videos as $video)

            @continue ($video->isAvailable())

            @php
                $partnya = $video->episode
                    ? ($video->episode->drama?->title ?? 'Drama').' Part '
                        .str_pad((string) $video->episode->episode_number, 2, '0', STR_PAD_LEFT)
                    : null;

                $peringatan = $partnya
                    ? $partnya.' akan kosong lagi dan '.$video->original_filename
                        .' kembali ke tab Belum terpasang.'
                    : $video->original_filename.' akan kembali ke tab Belum terpasang.';

                $peringatan .= ' Berkasnya tidak dihapus dari storage, jadi pemasangannya bisa diulang.';
            @endphp

            <form id="inbox-release-{{ $video->id }}"
                  method="POST"
                  action="{{ route('admin.video-inbox.release', ['video' => $video->id]) }}"
                  hidden
                  data-confirm
                  data-confirm-title="Lepas dari part?"
                  data-confirm-ok="Lepas"
                  data-confirm-message="{{ $peringatan }}">
                @csrf
            </form>

            {{-- Pasangannya untuk pemindahan. Tanpa `data-confirm`: tidak ada
                 yang hilang di sini — part lama kosong, part baru terisi, dan
                 salah tujuan bisa langsung dipindahkan lagi. Dialog untuk
                 tindakan yang mudah dibatalkan hanya melatih orang menekan
                 "Ya" tanpa membaca. --}}
            @if ($video->episode)
                <form id="inbox-move-{{ $video->id }}"
                      method="POST"
                      action="{{ route('admin.video-inbox.move', ['video' => $video->id]) }}"
                      hidden>
                    @csrf
                </form>
            @endif

        @endforeach

        @if ($videos->hasPages())
            <div class="inbox-pager">
                {{ $videos->links() }}
            </div>
        @endif

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            const form = document.querySelector('[data-inbox-form]');

            if (!form) {
                return;
            }

            const submit      = form.querySelector('[data-inbox-submit]');
            const pickAll     = form.querySelector('[data-pick-all]');
            const pickNone    = form.querySelector('[data-pick-none]');
            const counter     = form.querySelector('[data-inbox-count]');
            const templateUrl = form.dataset.episodesUrl;

            // Hanya baris yang benar-benar bisa dipasang yang punya checkbox.
            const rows = Array.from(form.querySelectorAll('[data-inbox-row]'))
                .filter((row) => row.querySelector('[data-inbox-check]'));

            if (!rows.length) {
                return;
            }

            /*
             * Daftar episode per drama, disimpan setelah sekali diambil.
             *
             * Sepuluh video dari drama yang sama hanya menembak server satu
             * kali, bukan sepuluh kali. Nilainya berupa Promise supaya dua
             * baris yang memilih drama sama berbarengan tetap berbagi satu
             * permintaan.
             */
            const cacheEpisode = new Map();

            const ambilEpisode = (dramaId) => {
                if (!cacheEpisode.has(dramaId)) {
                    const url = templateUrl.replace(/\/0(?=\/?$)/, '/' + dramaId);

                    cacheEpisode.set(
                        dramaId,
                        fetch(url, { headers: { 'Accept': 'application/json' } })
                            .then((response) => {
                                if (!response.ok) {
                                    throw new Error('Gagal mengambil part.');
                                }

                                return response.json();
                            })
                            .then(({ data }) => data || [])
                            .catch((error) => {
                                // Kegagalan tidak boleh ikut tersimpan, kalau
                                // tidak percobaan berikutnya ikut gagal terus.
                                cacheEpisode.delete(dramaId);
                                throw error;
                            })
                    );
                }

                return cacheEpisode.get(dramaId);
            };

            /*
             * Menebak nomor episode dari nama berkas.
             *
             * Pola "ep12", "EP-03", "episode 7", "e004" diutamakan karena itu
             * yang benar-benar menyatakan nomor episode. Bila tidak ada, angka
             * TERAKHIR pada nama dipakai — pada nama seperti
             * "drama-2024-ep-05.mp4" angka terakhirlah nomor episodenya,
             * bukan tahunnya.
             */
            const tebakNomor = (filename) => {
                const nama = String(filename || '').replace(/\.[a-z0-9]+$/i, '');

                const eksplisit = nama.match(/(?:ep|eps|episode|e)[\s._-]*(\d{1,4})/i);

                if (eksplisit) {
                    return parseInt(eksplisit[1], 10);
                }

                const semua = nama.match(/\d{1,4}/g);

                return semua ? parseInt(semua[semua.length - 1], 10) : null;
            };

            const setOpsi = (select, teks) => {
                select.innerHTML = `<option value="">${teks}</option>`;
                select.disabled = true;
            };

            const isiEpisode = (row, episodes, pilihan = null) => {
                const select = row.querySelector('[data-inbox-episode]');

                select.innerHTML = '';

                const kosong = document.createElement('option');
                kosong.value = '';
                kosong.textContent = '— pilih part —';
                select.appendChild(kosong);

                episodes.forEach((item) => {
                    const option = document.createElement('option');

                    option.value = item.id;
                    option.textContent = item.has_video
                        ? `${item.label} (sudah ada video — akan dilewati)`
                        : item.label;

                    select.appendChild(option);
                });

                select.disabled = false;

                if (pilihan) {
                    select.value = String(pilihan);
                    return;
                }

                // Tebakan dari nama berkas dipilihkan, tapi tetap bisa diubah.
                const nomor = tebakNomor(row.dataset.filename);

                if (nomor !== null) {
                    const cocok = episodes.find(
                        (item) => Number(item.number) === nomor && !item.has_video
                    );

                    if (cocok) {
                        select.value = String(cocok.id);
                    }
                }
            };

            const muatBaris = async (row, pilihan = null) => {
                const drama  = row.querySelector('[data-inbox-drama]');
                const select = row.querySelector('[data-inbox-episode]');

                if (!drama.value) {
                    setOpsi(select, '— pilih drama dulu —');
                    segarkanBaris(row);
                    segarkanPanel();
                    return;
                }

                setOpsi(select, '— memuat part —');

                try {
                    const episodes = await ambilEpisode(drama.value);

                    if (!episodes.length) {
                        setOpsi(select, '— belum ada part —');
                    } else {
                        isiEpisode(row, episodes, pilihan);
                    }
                } catch (error) {
                    setOpsi(select, 'Gagal memuat part');
                }

                segarkanBaris(row);
                segarkanPanel();
            };

            /** Menyalakan/mematikan input satu baris sesuai centangnya. */
            function segarkanBaris(row) {
                const check   = row.querySelector('[data-inbox-check]');
                const select  = row.querySelector('[data-inbox-episode]');
                const drama   = row.querySelector('[data-inbox-drama]');
                const dramaId = row.querySelector('[data-inbox-drama-id]');

                row.classList.toggle('is-picked', check.checked);

                // Input yang mati tidak ikut terkirim — itulah cara baris yang
                // tidak dicentang tetap keluar dari permintaan. Dropdown yang
                // belum berisi episode juga tidak perlu ikut.
                if (select) {
                    const adaEpisode = select.options.length > 1;

                    select.disabled = !check.checked || !adaEpisode;
                }

                if (dramaId) {
                    dramaId.value = drama.value;
                    dramaId.disabled = !check.checked;
                }
            }

            /** Menandai dua baris atau lebih yang menuju episode yang sama. */
            function periksaBentrok() {
                const dipakai = new Map();

                rows.forEach((row) => {
                    const check  = row.querySelector('[data-inbox-check]');
                    const select = row.querySelector('[data-inbox-episode]');
                    const nota   = row.querySelector('[data-inbox-clash]');

                    row.classList.remove('is-clash');

                    if (nota) {
                        nota.hidden = true;
                    }

                    if (!check.checked || !select || !select.value) {
                        return;
                    }

                    if (dipakai.has(select.value)) {
                        [row, dipakai.get(select.value)].forEach((bentrok) => {
                            bentrok.classList.add('is-clash');

                            const pesan = bentrok.querySelector('[data-inbox-clash]');

                            if (pesan) {
                                pesan.hidden = false;
                            }
                        });
                    } else {
                        dipakai.set(select.value, row);
                    }
                });

                return !form.querySelector('.is-clash');
            }

            function segarkanPanel() {
                const dicentang = rows.filter(
                    (row) => row.querySelector('[data-inbox-check]').checked
                );

                const bersih = periksaBentrok();

                const tanpaEpisode = dicentang.filter((row) => {
                    const select = row.querySelector('[data-inbox-episode]');
                    return !select || !select.value;
                }).length;

                submit.disabled = dicentang.length === 0 || !bersih;

                if (dicentang.length === 0) {
                    counter.textContent = 'Belum ada video dicentang.';
                    return;
                }

                let pesan = `${dicentang.length} video dicentang.`;

                if (tanpaEpisode > 0) {
                    pesan += ` ${tanpaEpisode} belum dipilih episodenya.`;
                }

                if (!bersih) {
                    pesan += ' Ada part yang dipakai lebih dari satu video.';
                }

                counter.textContent = pesan;
            }

            /*
             * Pilihan sebelumnya, dikembalikan setelah permintaan ditolak.
             * Tanpa ini admin yang salah pada satu baris harus mengisi ulang
             * selusin baris yang sudah benar.
             */
            const pilihanLama = new Map();

            try {
                const mentah = JSON.parse(form.dataset.oldPairs || '{}');

                Object.values(mentah || {}).forEach((pair) => {
                    if (pair && pair.video_id) {
                        pilihanLama.set(String(pair.video_id), {
                            drama: String(pair.drama_id ?? ''),
                            episode: String(pair.episode_id ?? ''),
                        });
                    }
                });
            } catch (error) {
                // Input lama yang rusak bukan alasan untuk mematikan halaman.
            }

            rows.forEach((row) => {
                const check  = row.querySelector('[data-inbox-check]');
                const drama  = row.querySelector('[data-inbox-drama]');
                const select = row.querySelector('[data-inbox-episode]');

                check.addEventListener('change', () => {
                    segarkanBaris(row);
                    segarkanPanel();
                });

                drama.addEventListener('change', () => muatBaris(row));

                if (select) {
                    select.addEventListener('change', segarkanPanel);
                }
            });

            pickAll.addEventListener('click', () => {
                rows.forEach((row) => {
                    row.querySelector('[data-inbox-check]').checked = true;
                    segarkanBaris(row);
                });

                segarkanPanel();
            });

            pickNone.addEventListener('click', () => {
                rows.forEach((row) => {
                    row.querySelector('[data-inbox-check]').checked = false;
                    segarkanBaris(row);
                });

                segarkanPanel();
            });

            form.addEventListener('submit', (event) => {
                if (!periksaBentrok()) {
                    event.preventDefault();
                    segarkanPanel();
                }
            });

            /*
             * Memulihkan keadaan setelah permintaan ditolak: centangan, drama,
             * dan episode dikembalikan seperti sebelum tombol ditekan.
             *
             * Baris yang episodenya memang dibiarkan kosong tetap dipulihkan
             * kosong — itu justru baris yang perlu dibetulkan, dan menebak
             * isinya hanya akan menyembunyikan sebab penolakannya.
             */
            const pulihkan = () => {
                const menunggu = rows.map((row) => {
                    const lama = pilihanLama.get(row.dataset.videoId);

                    if (lama === undefined) {
                        return null;
                    }

                    row.querySelector('[data-inbox-check]').checked = true;

                    if (!lama.drama) {
                        segarkanBaris(row);
                        return null;
                    }

                    row.querySelector('[data-inbox-drama]').value = lama.drama;

                    return muatBaris(row, lama.episode || null);
                });

                Promise.all(menunggu.filter(Boolean)).then(segarkanPanel);

                segarkanPanel();
            };

            if (pilihanLama.size) {
                pulihkan();
            } else {
                segarkanPanel();
            }
        });
    </script>

    <script>
        /*
         * Kotak "Pindahkan" pada tab Sudah terpasang.
         *
         * Sengaja TERPISAH dari skrip pemasangan di atas, bukan disisipkan ke
         * dalamnya. Skrip itu berhenti lebih awal ketika tidak ada satu pun
         * baris bercentang — dan di tab ini memang tidak ada, karena video yang
         * sudah terpasang tidak punya checkbox. Menumpangkan kode ini di sana
         * berarti ia tidak akan pernah berjalan justru di satu-satunya tab yang
         * membutuhkannya.
         *
         * Berdiri sendiri juga berarti kesalahan di sini tidak bisa mematikan
         * alur pemasangan yang sudah berjalan.
         */
        document.addEventListener('DOMContentLoaded', () => {

            const kotak = Array.from(document.querySelectorAll('[data-move-box]'));

            if (!kotak.length) {
                return;
            }

            const templateUrl = document
                .querySelector('[data-inbox-form]')
                ?.dataset.episodesUrl;

            if (!templateUrl) {
                return;
            }

            // Sepuluh video dari drama yang sama cukup satu permintaan.
            const cache = new Map();

            const ambilEpisode = (dramaId) => {
                if (!cache.has(dramaId)) {
                    const url = templateUrl.replace(/\/0(?=\/?$)/, '/' + dramaId);

                    cache.set(
                        dramaId,
                        fetch(url, { headers: { 'Accept': 'application/json' } })
                            .then((r) => {
                                if (!r.ok) {
                                    throw new Error('Gagal mengambil part.');
                                }

                                return r.json();
                            })
                            .then(({ data }) => data || [])
                            .catch((e) => {
                                cache.delete(dramaId);
                                throw e;
                            })
                    );
                }

                return cache.get(dramaId);
            };

            kotak.forEach((box) => {

                const baris  = box.closest('[data-inbox-row]');
                const tombol = baris?.querySelector('[data-move-toggle]');
                const drama  = box.querySelector('[data-move-drama]');
                const part   = box.querySelector('[data-move-episode]');
                const kirim  = box.querySelector('[data-move-submit]');

                if (!tombol) {
                    return;
                }

                tombol.addEventListener('click', () => {
                    const tampil = box.hidden;

                    box.hidden = !tampil;
                    tombol.setAttribute('aria-expanded', String(tampil));
                    tombol.textContent = tampil ? 'Batal pindah' : 'Pindahkan';

                    // Daftar partnya baru diambil saat kotaknya dibuka. Halaman
                    // ini memuat 20 baris sekaligus; mengambil semuanya di muka
                    // berarti 20 permintaan untuk sesuatu yang mungkin tidak
                    // satu pun dipakai.
                    if (tampil && drama && drama.value && part.options.length <= 1) {
                        muat();
                    }
                });

                if (!drama || !part || !kirim) {
                    return;
                }

                const setOpsi = (teks) => {
                    part.innerHTML = '<option value="">' + teks + '</option>';
                    part.disabled = true;
                    kirim.disabled = true;
                };

                async function muat() {
                    if (!drama.value) {
                        setOpsi('— pilih drama dulu —');
                        return;
                    }

                    setOpsi('— memuat part —');

                    let episodes;

                    try {
                        episodes = await ambilEpisode(drama.value);
                    } catch (e) {
                        setOpsi('Gagal memuat part');
                        return;
                    }

                    /*
                     * Part yang sudah berisi video DIBUANG dari daftar, bukan
                     * ditampilkan sebagai pilihan yang nanti ditolak server.
                     *
                     * Ini berbeda dari daftar saat memasang, yang masih
                     * menampilkannya dengan keterangan "akan dilewati" — di
                     * sana admin memasang belasan sekaligus dan perlu tahu
                     * mana yang terlewat. Di sini tindakannya satu video, dan
                     * satu-satunya hasil dari memilih part berisi adalah
                     * penolakan. Pilihan yang pasti gagal lebih baik tidak ada.
                     */
                    const bisa = episodes.filter((item) => !item.has_video);

                    if (!bisa.length) {
                        setOpsi('— semua partnya sudah berisi —');
                        return;
                    }

                    part.innerHTML = '';

                    const kosong = document.createElement('option');
                    kosong.value = '';
                    kosong.textContent = '— pilih part —';
                    part.appendChild(kosong);

                    bisa.forEach((item) => {
                        const opsi = document.createElement('option');
                        opsi.value = item.id;
                        opsi.textContent = item.label;
                        part.appendChild(opsi);
                    });

                    part.disabled = false;
                    kirim.disabled = true;
                }

                drama.addEventListener('change', muat);

                part.addEventListener('change', () => {
                    kirim.disabled = !part.value;
                });
            });
        });
    </script>

@endsection
