@extends('web.layouts.admin')

@section('title', 'Video Inbox')

@section('content')

    <style>
        .inbox-wrap { max-width: 920px; }

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

        .inbox-head {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

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

        .inbox-form {
            display: flex;
            flex-direction: row;
            align-items: flex-end;
            justify-content: flex-start;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding: 12px 0 0;
            border-top: 1px solid rgba(255,255,255,.08);
        }

        .inbox-form .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1 1 200px;
            min-width: 160px;
            margin: 0;
        }

        .inbox-form label {
            display: block;
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .55;
            margin-bottom: 5px;
        }

        .inbox-form .control { width: 100%; }

        .inbox-form .inbox-actions {
            display: flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

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
            Video di halaman ini sudah tersimpan di Cloudflare R2 melalui worker
            Telegram. Pilih episode tujuan untuk memasangkan video tanpa
            mengunggah ulang berkas.
        </p>

        @if (session('success'))
            <div class="inbox-alert">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="inbox-alert field-error">{{ session('error') }}</div>
        @endif

        <div class="inbox-list">

            @forelse ($videos as $video)

                <section class="inbox-row">

                    <div class="inbox-head">
                        <h2 class="inbox-name">{{ $video->original_filename }}</h2>

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

                    @elseif ($video->isAvailable())

                        <form method="POST"
                              action="{{ route('admin.video-inbox.assign', $video) }}"
                              class="inbox-form"
                              data-inbox-assign
                              data-episodes-url="{{ route('admin.episode.video.episodes', ['drama' => 0]) }}">

                            @csrf

                            <div class="field">
                                <label for="inbox-drama-{{ $video->id }}">Drama *</label>

                                <select id="inbox-drama-{{ $video->id }}"
                                        class="control"
                                        data-inbox-drama
                                        required>

                                    <option value="">— pilih drama —</option>

                                    @foreach ($dramas as $drama)
                                        <option value="{{ $drama->id }}">
                                            {{ $drama->title }}
                                        </option>
                                    @endforeach

                                </select>
                            </div>

                            <div class="field">
                                <label for="inbox-episode-{{ $video->id }}">Episode *</label>

                                <select id="inbox-episode-{{ $video->id }}"
                                        name="episode_id"
                                        class="control"
                                        data-inbox-episode
                                        required
                                        disabled>

                                    <option value="">— pilih drama dulu —</option>

                                </select>
                            </div>

                            <div class="inbox-actions">
                                <a href="{{ route('admin.episode.batch') }}"
                                   class="btn btn-ghost btn-sm"
                                   data-add-episode
                                   data-base-url="{{ route('admin.episode.batch') }}">
                                    + Episode
                                </a>

                                @if ($video->checksum)
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        Pasang
                                    </button>
                                @endif
                            </div>

                            @error('episode_id')
                                <p class="inbox-note field-error">{{ $message }}</p>
                            @enderror

                            @if (! $video->checksum)
                                <p class="inbox-note field-error">
                                    Belum ada checksum SHA-256, video belum dapat dipasang.
                                </p>
                            @endif

                        </form>

                    @endif

                </section>

            @empty

                <section class="inbox-row">
                    <h2 class="inbox-name">Inbox kosong</h2>
                    <p class="inbox-meta"><span>Belum ada video dari worker Telegram.</span></p>
                </section>

            @endforelse

        </div>

        @if ($videos->hasPages())
            <div class="inbox-pager">
                {{ $videos->links() }}
            </div>
        @endif

    </div>


    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-inbox-assign]').forEach((form) => {
                const drama = form.querySelector('[data-inbox-drama]');
                const episode = form.querySelector('[data-inbox-episode]');
		const addEpisode = form.querySelector('[data-add-episode]');
                const templateUrl = form.dataset.episodesUrl;

                if (!drama || !episode || !templateUrl) {
                    return;
                }

                drama.addEventListener('change', async () => {
                    const dramaId = drama.value;

if (addEpisode) {
    const baseUrl = addEpisode.dataset.baseUrl;

    addEpisode.href = dramaId
        ? `${baseUrl}?drama_id=${encodeURIComponent(dramaId)}`
        : baseUrl;
}

                    episode.innerHTML =
                        '<option value="">— memuat episode —</option>';

                    episode.disabled = true;

                    if (!dramaId) {
                        episode.innerHTML =
                            '<option value="">— pilih drama dulu —</option>';
                        return;
                    }

                    const url = templateUrl.replace(/\/0(?=\/?$)/, '/' + dramaId);

                    try {
                        const response = await fetch(url, {
                            headers: {
                                'Accept': 'application/json',
                            },
                        });

                        if (!response.ok) {
                            throw new Error('Gagal mengambil episode.');
                        }

                        const { data } = await response.json();

episode.innerHTML =
    '<option value="">— pilih episode —</option>';

if (!data.length) {
    episode.innerHTML =
        '<option value="">— belum ada episode —</option>';

    episode.disabled = true;
    return;
}

data.forEach((item) => {
    const option = document.createElement('option');

    option.value = item.id;
    option.textContent = item.has_video
        ? `${item.label} (sudah ada video)`
        : item.label;

    episode.appendChild(option);
});

episode.disabled = false;
                    } catch (error) {
                        episode.innerHTML =
                            '<option value="">Gagal memuat episode</option>';

                        episode.disabled = true;
                    }
                });
            });
        });
    </script>

@endsection