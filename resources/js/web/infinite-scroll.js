const ROOT_SELECTOR = '[data-infinite-scroll]';
const GRID_SELECTOR = '[data-infinite-grid]';
const SENTINEL_SELECTOR = '[data-infinite-sentinel]';

export default function infiniteScroll() {
    const root = document.querySelector(ROOT_SELECTOR);

    if (!root || root.dataset.infiniteReady === '1') {
        return;
    }

    const grid = root.querySelector(GRID_SELECTOR);
    const sentinel = root.querySelector(SENTINEL_SELECTOR);

    if (!grid || !sentinel) {
        return;
    }

    root.dataset.infiniteReady = '1';

    let nextUrl = sentinel.dataset.nextUrl || '';
    let loading = false;
    let finished = nextUrl === '';
    let controller = null;

    const status = sentinel.querySelector('[data-infinite-status]');

    const setState = (state) => {
        sentinel.dataset.state = state;

        if (!status) {
            return;
        }

        if (state === 'loading') {
            status.textContent = 'Memuat drama lainnya...';
        } else if (state === 'error') {
            status.textContent = 'Gagal memuat. Ketuk untuk mencoba lagi.';
        } else if (state === 'done') {
            status.textContent = 'Semua drama sudah ditampilkan';
        } else {
            status.textContent = '';
        }
    };

    let observer = null;

    const finish = () => {
        finished = true;
        nextUrl = '';

        sentinel.dataset.nextUrl = '';

        setState('done');

        if (observer) {
            observer.disconnect();
        }
    };

    const loadNext = async () => {
        if (loading || finished || !nextUrl) {
            return;
        }

        loading = true;
        setState('loading');

        controller = new AbortController();

        try {
            const response = await fetch(nextUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();

            const doc = new DOMParser().parseFromString(
                html,
                'text/html'
            );

            const incomingRoot = doc.querySelector(ROOT_SELECTOR);

            const incomingGrid =
                incomingRoot?.querySelector(GRID_SELECTOR);

            const incomingSentinel =
                incomingRoot?.querySelector(SENTINEL_SELECTOR);

            if (!incomingGrid || !incomingSentinel) {
                throw new Error(
                    'Markup infinite scroll tidak ditemukan.'
                );
            }

            const cards = Array.from(incomingGrid.children);

            if (cards.length === 0) {
                finish();
                return;
            }

            const fragment = document.createDocumentFragment();

            cards.forEach((card) => {
                fragment.appendChild(card);
            });

            grid.appendChild(fragment);

            nextUrl =
                incomingSentinel.dataset.nextUrl || '';

            sentinel.dataset.nextUrl = nextUrl;

            if (!nextUrl) {
                finish();
            } else {
                setState('idle');
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                console.error(
                    'DramaVerse infinite scroll:',
                    error
                );

                setState('error');
            }
        } finally {
            loading = false;
            controller = null;
        }
    };

    sentinel.addEventListener('click', () => {
        if (sentinel.dataset.state === 'error') {
            loadNext();
        }
    });

    if ('IntersectionObserver' in window && !finished) {
        observer = new IntersectionObserver(
            (entries) => {
                if (
                    entries.some(
                        (entry) => entry.isIntersecting
                    )
                ) {
                    loadNext();
                }
            },
            {
                /*
                 * Tidak perlu menunggu user benar-benar sampai bawah.
                 * Saat tinggal sekitar 500px, halaman berikutnya
                 * sudah mulai diambil.
                 */
                rootMargin: '500px 0px',
                threshold: 0.01,
            }
        );

        observer.observe(sentinel);
    } else if (!finished) {
        sentinel.dataset.state = 'error';

        if (status) {
            status.textContent = 'Muat drama berikutnya';
        }
    } else {
        setState('done');
    }

    window.addEventListener(
        'beforeunload',
        () => {
            controller?.abort();
        },
        { once: true }
    );
}