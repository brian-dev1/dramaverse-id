/**
 * Sinkronisasi progres pemutar dengan server.
 *
 * - Melanjutkan dari posisi terakhir saat metadata siap
 * - Mengirim progres setiap 15 detik dan saat halaman ditutup
 * - Menandai selesai ketika video berakhir
 */
export default function player() {
    const video = document.getElementById('player');

    if (!video) return;

    const episodeId = video.dataset.episode;
    const resumeAt  = Number.parseInt(video.dataset.progress || '0', 10);
    const token     = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!episodeId || !token) return;

    const post = (url, body, useBeacon = false) => {
        const payload = JSON.stringify({ episode_id: episodeId, ...body });

        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload,
            keepalive: true,
        }).catch(() => { /* jaringan putus — progres akan dikirim ulang nanti */ });
    };

    // --- Lanjutkan dari posisi terakhir ---
    video.addEventListener('loadedmetadata', () => {
        if (resumeAt > 0 && resumeAt < video.duration - 10) {
            video.currentTime = resumeAt;
        }
    }, { once: true });

    // --- Kirim progres berkala ---
    let lastSent = 0;

    video.addEventListener('timeupdate', () => {
        const now = Math.floor(video.currentTime);

        if (now - lastSent >= 15) {
            lastSent = now;
            post('/api/v1/player/progress', { progress: now });
        }
    });

    // --- Simpan saat meninggalkan halaman ---
    window.addEventListener('pagehide', () => {
        post('/api/v1/player/progress', { progress: Math.floor(video.currentTime) }, true);
    });

    // --- Tandai selesai ---
    video.addEventListener('ended', () => {
        post(`/api/v1/player/completed/${episodeId}`, { progress: Math.floor(video.duration) });

        const next = document.querySelector('.player-nav .btn-primary');

        if (next) {
            setTimeout(() => { window.location.href = next.href; }, 5000);
        }
    });
}
