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

        /* --- Panel kendali --- */

        .inbox-panel {
            position: sticky;
            top: 0;
            z-index: 5;
            margin: 0 0 16px;
            padding: 14px 16px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 10px;
            background: #1a0f0c;
            box-shadow: 0 6px 18px rgba(0,0,0,.35);
        }

        .inbox-panel-grid {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .inbox-panel .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1 1 260px;
            min-width: 200px;
            margin: 0;
        }

        .inbox-panel label {
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .55;
        }

        .inbox-panel .control { width: 100%; }

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

        .inbox-hint {
            flex: 1 1 100%;
            margin: 6px 0 0;
            font-size: 12px;
            opacity: .5;
            line-height: 1.6;
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
            margin: 8px 0 0;
            font-size: 12.5px;
            opacity: .7;
        }

        .inbox-episode {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.08);
        }

        .inbox-episode label {
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .55;
            flex: 0 0 auto;
        }

        .inbox-episode select {
            flex: 1 1 260px;
            min-width: 200px;
        }

        .inbox-note {
            flex: 1 1 100%;
            margin: 6px 0 0;
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
            Video di halaman ini sudah tersimpan di storage provider lewat worker
            Telegram. Pilih drama sekali di panel atas, centang video yang mau
            dipasang, tentukan episodenya, lalu pasang semuanya dalam satu kali
            tekan. Tidak ada berkas yang diunduh atau diunggah ulang.
        </p>

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

            <div class="inbox-panel">

                <div class="inbox-panel-grid">

                    <div class="field">
                        <label for="inbox-drama">Drama tujuan *</label>

                        <select id="inbox-drama"
                                name="drama_id"
                                class="control"
                                data-inbox-drama
                                required>

                            <option value="">— pilih drama —</option>

                            @foreach ($dramas as $drama)
                                <option value="{{ $drama->id }}"
                                        @selected((int) old('drama_id') === $drama->id)>
                                    {{ $drama->title }}
                                </option>
                            @endforeach

                        </select>
                    </div>

                    <div class="inbox-panel-actions">
                        <button type="button" class="btn btn-ghost btn-sm" data-pick-all disabled>
                            Centang semua
                        </button>

                        <button type="button" class="btn btn-ghost btn-sm" data-pick-none disabled>
                            Bersihkan
                        </button>

                        <a href="{{ route('admin.episode.batch') }}"
                           class="btn btn-ghost btn-sm"
                           data-add-episode
                           data-base-url="{{ route('admin.episode.batch') }}">
                            + Episode
                        </a>

                        <button type="submit" class="btn btn-primary btn-sm" data-inbox-submit disabled>
                            Pasang video terpilih
                        </button>
                    </div>

                    <p class="inbox-count" data-inbox-count>Belum ada drama dipilih.</p>

                    <p class="inbox-hint">
                        Nomor episode ditebak dari nama berkas dan sudah dipilihkan —
                        periksa dulu, ubah bila keliru. Episode yang sudah punya video
                        akan dilewati, begitu juga episode yang belum dibuat.
                    </p>

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
                                           data-inbox-check
                                           disabled>
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

                        @if ($video->status === 'assigned' && $video->episode)

                            <p class="inbox-assigned">
                                Terpasang ke
                                <strong>
                                    {{ $video->episode->drama?->title ?? 'Drama' }}
                                    — Episode {{ $video->episode->episode_number }}
                                </strong>
                            </p>

                        @elseif ($bisaDipasang)

                            <div class="inbox-episode">

                                <label for="inbox-episode-{{ $video->id }}">Episode</label>

                                <select id="inbox-episode-{{ $video->id }}"
                                        name="pairs[{{ $video->id }}][episode_id]"
                                        class="control"
                                        data-inbox-episode
                                        disabled>

                                    <option value="">— pilih drama dulu —</option>

                                </select>

                                <p class="inbox-note field-error" data-inbox-clash hidden>
                                    Episode ini juga dipilih video lain.
                                </p>

                            </div>

                        @elseif ($video->isAvailable())

                            <p class="inbox-note field-error">
                                Belum ada checksum SHA-256, video belum dapat dipasang.
                            </p>

                        @endif

                    </section>

                @empty

                    <section class="inbox-row">
                        <h2 class="inbox-name">Inbox kosong</h2>
                        <p class="inbox-meta"><span>Belum ada video dari worker Telegram.</span></p>
                    </section>

                @endforelse

            </div>

        </form>

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

            const drama       = form.querySelector('[data-inbox-drama]');
            const submit      = form.querySelector('[data-inbox-submit]');
            const pickAll     = form.querySelector('[data-pick-all]');
            const pickNone    = form.querySelector('[data-pick-none]');
            const addEpisode  = form.querySelector('[data-add-episode]');
            const counter     = form.querySelector('[data-inbox-count]');
            const templateUrl = form.dataset.episodesUrl;

            // Hanya baris yang benar-benar bisa dipasang yang punya checkbox.
            const rows = Array.from(form.querySelectorAll('[data-inbox-row]'))
                .filter((row) => row.querySelector('[data-inbox-check]'));

            let episodes = [];

            /*
             * Pilihan sebelumnya, dikembalikan setelah permintaan ditolak.
             * Tanpa ini admin yang salah pada satu baris harus mencentang
             * ulang selusin video yang sudah benar.
             *
             * Bentuknya daftar { video_id, episode_id } — persis seperti yang
             * dinormalkan controller sebelum validasi.
             */
            const pilihanLama = new Map();

            try {
                const mentah = JSON.parse(form.dataset.oldPairs || '{}');

                Object.values(mentah || {}).forEach((pair) => {
                    if (pair && pair.video_id) {
                        pilihanLama.set(String(pair.video_id), String(pair.episode_id ?? ''));
                    }
                });
            } catch (error) {
                // Input lama yang rusak bukan alasan untuk mematikan halaman.
            }

            /**
             * Menebak nomor episode dari nama berkas.
             *
             * Pola "ep12", "EP-03", "episode 7", "e004" diutamakan karena itu
             * yang benar-benar menyatakan nomor episode. Bila tidak ada, angka
             * TERAKHIR pada nama dipakai — pada nama seperti
             * "drama-2024-ep-05.mp4" angka terakhirlah yang nomor episodenya,
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

            const opsiEpisode = (row) => {
                const select = row.querySelector('[data-inbox-episode]');

                select.innerHTML = '';

                const kosong = document.createElement('option');
                kosong.value = '';
                kosong.textContent = '— pilih episode —';
                select.appendChild(kosong);

                episodes.forEach((item) => {
                    const option = document.createElement('option');

                    option.value = item.id;
                    option.textContent = item.has_video
                        ? `${item.label} (sudah ada video — akan dilewati)`
                        : item.label;
                    option.dataset.number = item.number;
                    option.dataset.hasVideo = item.has_video ? '1' : '';

                    select.appendChild(option);
                });

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

                select.disabled = false;
            };

            /** Menyalakan/mematikan input satu baris sesuai centangnya. */
            const segarkanBaris = (row) => {
                const check   = row.querySelector('[data-inbox-check]');
                const select  = row.querySelector('[data-inbox-episode]');
                const dipilih = check.checked;

                row.classList.toggle('is-picked', dipilih);

                // Input yang mati tidak ikut terkirim — itulah cara baris yang
                // tidak dicentang tetap keluar dari permintaan.
                if (select) {
                    select.disabled = !dipilih;
                }
            };

            /** Menandai dua baris atau lebih yang menuju episode yang sama. */
            const periksaBentrok = () => {
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
            };

            const segarkanPanel = () => {
                const dicentang = rows.filter(
                    (row) => row.querySelector('[data-inbox-check]').checked
                );

                const bersih = periksaBentrok();

                const tanpaEpisode = dicentang.filter((row) => {
                    const select = row.querySelector('[data-inbox-episode]');
                    return !select || !select.value;
                }).length;

                submit.disabled = dicentang.length === 0 || !bersih;

                if (!drama.value) {
                    counter.textContent = 'Belum ada drama dipilih.';
                    return;
                }

                if (dicentang.length === 0) {
                    counter.textContent = 'Belum ada video dicentang.';
                    return;
                }

                let pesan = `${dicentang.length} video dicentang.`;

                if (tanpaEpisode > 0) {
                    pesan += ` ${tanpaEpisode} belum dipilih episodenya.`;
                }

                if (!bersih) {
                    pesan += ' Ada episode yang dipakai lebih dari satu video.';
                }

                counter.textContent = pesan;
            };

            const kunciSemua = (pesan) => {
                rows.forEach((row) => {
                    const check  = row.querySelector('[data-inbox-check]');
                    const select = row.querySelector('[data-inbox-episode]');

                    check.checked = false;
                    check.disabled = true;

                    row.classList.remove('is-picked', 'is-clash');

                    if (select) {
                        select.disabled = true;
                        select.innerHTML = `<option value="">${pesan}</option>`;
                    }
                });

                pickAll.disabled = true;
                pickNone.disabled = true;
            };

            drama.addEventListener('change', async () => {
                const dramaId = drama.value;

                if (addEpisode) {
                    const baseUrl = addEpisode.dataset.baseUrl;

                    addEpisode.href = dramaId
                        ? `${baseUrl}?drama_id=${encodeURIComponent(dramaId)}`
                        : baseUrl;
                }

                if (!dramaId) {
                    episodes = [];
                    kunciSemua('— pilih drama dulu —');
                    segarkanPanel();
                    return;
                }

                kunciSemua('— memuat episode —');
                counter.textContent = 'Memuat daftar episode…';

                // Satu permintaan untuk seluruh halaman, bukan satu per baris.
                const url = templateUrl.replace(/\/0(?=\/?$)/, '/' + dramaId);

                try {
                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        throw new Error('Gagal mengambil episode.');
                    }

                    const { data } = await response.json();

                    episodes = data || [];

                    if (!episodes.length) {
                        kunciSemua('— belum ada episode —');
                        counter.textContent =
                            'Drama ini belum punya episode. Buat dulu lewat tombol + Episode.';
                        submit.disabled = true;
                        return;
                    }

                    rows.forEach((row) => {
                        const check = row.querySelector('[data-inbox-check]');

                        check.disabled = false;

                        opsiEpisode(row);

                        // Pilihan sebelum permintaan ditolak dikembalikan apa
                        // adanya — termasuk episode kosong yang jadi sebab
                        // penolakan, supaya terlihat mana yang perlu dibetulkan.
                        if (pilihanLama.has(row.dataset.videoId)) {
                            const select = row.querySelector('[data-inbox-episode]');

                            check.checked = true;
                            select.value = pilihanLama.get(row.dataset.videoId);
                        }

                        // Dropdown ikut mati lagi sampai barisnya dicentang.
                        segarkanBaris(row);
                    });

                    pickAll.disabled = false;
                    pickNone.disabled = false;

                    segarkanPanel();

                } catch (error) {
                    kunciSemua('Gagal memuat episode');
                    counter.textContent = 'Gagal memuat episode. Coba pilih ulang dramanya.';
                    submit.disabled = true;
                }
            });

            rows.forEach((row) => {
                const check  = row.querySelector('[data-inbox-check]');
                const select = row.querySelector('[data-inbox-episode]');

                check.addEventListener('change', () => {
                    segarkanBaris(row);
                    segarkanPanel();
                });

                if (select) {
                    select.addEventListener('change', segarkanPanel);
                }
            });

            pickAll.addEventListener('click', () => {
                rows.forEach((row) => {
                    const check = row.querySelector('[data-inbox-check]');

                    if (!check.disabled) {
                        check.checked = true;
                        segarkanBaris(row);
                    }
                });

                segarkanPanel();
            });

            pickNone.addEventListener('click', () => {
                rows.forEach((row) => {
                    const check = row.querySelector('[data-inbox-check]');

                    check.checked = false;
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

            // Setelah validasi gagal, drama yang tadi dipilih dikembalikan
            // browser — daftar episodenya perlu dimuat ulang sendiri.
            if (drama.value) {
                drama.dispatchEvent(new Event('change'));
            }
        });
    </script>

@endsection
