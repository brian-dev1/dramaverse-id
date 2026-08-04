@extends('web.layouts.admin')

@section('title', 'Video Inbox')

@section('content')

    <div class="form-grid">

        <section class="form-card form-main">
            <h2>Video Inbox</h2>

            <p class="field-hint">
                Video di halaman ini sudah tersimpan di Cloudflare R2 melalui
                worker Telegram. Pilih episode tujuan untuk memasangkan video
                tanpa mengunggah ulang berkas.
            </p>

            @if (session('success'))
                <div class="upload-result">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="field-error">
                    {{ session('error') }}
                </div>
            @endif
        </section>

        @forelse ($videos as $video)

            <section class="form-card">

                <h2>{{ $video->original_filename }}</h2>

                <dl class="file-facts">
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <span class="badge badge-status">
                                {{ $video->status === 'available' ? 'Tersedia' : 'Terpasang' }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt>Ukuran</dt>
                        <dd>
                            {{ number_format($video->size / 1024 / 1024, 1) }} MB
                        </dd>
                    </div>

                    <div>
                        <dt>Provider</dt>
                        <dd>
                            {{ $video->provider?->name ?? 'Provider tidak ditemukan' }}
                        </dd>
                    </div>

                    <div>
                        <dt>Object Key</dt>
                        <dd>
                            <code>{{ $video->object_key }}</code>
                        </dd>
                    </div>

                    <div>
                        <dt>Masuk</dt>
                        <dd>
                            {{ $video->uploaded_at?->format('d M Y H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt>Checksum</dt>
                        <dd>
                            @if ($video->checksum)
                                Tersedia
                            @else
                                Belum tersedia
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($video->status === 'assigned' && $video->episode)

                    <p class="field-hint">
                        Video ini sudah dipasang ke
                        <strong>
                            {{ $video->episode->drama?->title ?? 'Drama' }}
                            — Episode {{ $video->episode->episode_number }}
                        </strong>.
                    </p>

                @elseif ($video->isAvailable())

                    <form method="POST"
                          action="{{ route('admin.video-inbox.assign', $video) }}"
                          class="admin-form"
                          data-inbox-assign
                          data-episodes-url="{{ route('admin.episode.video.episodes', ['drama' => 0]) }}">

                        @csrf

                        <div class="field">
                            <label for="inbox-drama-{{ $video->id }}">
                                Drama
                                <span class="field-required" aria-hidden="true">*</span>
                            </label>

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

                            <p class="field-hint">
                                Pilih drama terlebih dahulu.
                            </p>
                        </div>

                        <div class="field">
                            <label for="inbox-episode-{{ $video->id }}">
                                Episode
                                <span class="field-required" aria-hidden="true">*</span>
                            </label>

                            <select id="inbox-episode-{{ $video->id }}"
                                    name="episode_id"
                                    class="control"
                                    data-inbox-episode
                                    required
                                    disabled>

                                <option value="">
                                    — pilih drama dulu —
                                </option>

                            </select>

                            <p class="field-hint">
                                Pilih episode tujuan, atau buat episode baru jika belum tersedia.
                            </p>

                            <a href="{{ route('admin.episode.batch') }}"
                               class="btn btn-ghost btn-sm"
                               data-add-episode
                               data-base-url="{{ route('admin.episode.batch') }}">
                                + Tambah Episode
                            </a>

                            @error('episode_id')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        @if (! $video->checksum)

                            <p class="field-error">
                                Video ini belum memiliki checksum SHA-256 sehingga
                                belum dapat dipasang ke episode.
                            </p>

                        @else

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    Pasang ke Episode
                                </button>
                            </div>

                        @endif

                    </form>

                @endif

            </section>

        @empty

            <section class="form-card form-main">
                <h2>Inbox kosong</h2>

                <p class="field-hint">
                    Belum ada video dari worker Telegram.
                </p>
            </section>

        @endforelse

    </div>

    @if ($videos->hasPages())
        <div class="form-actions">
            {{ $videos->links() }}
        </div>
    @endif


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