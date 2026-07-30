/**
 * Panel admin: sidebar, aksi massal, dialog konfirmasi, pratinjau unggahan,
 * nomor episode otomatis, dan toast yang menghilang sendiri.
 */

const SIDEBAR_KEY = 'dv-admin-sidebar';

function sidebar() {
    const shell = document.querySelector('[data-shell]');
    if (!shell) return;

    if (localStorage.getItem(SIDEBAR_KEY) === 'collapsed') {
        shell.classList.add('sidebar-collapsed');
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            shell.classList.toggle('sidebar-collapsed');
            localStorage.setItem(
                SIDEBAR_KEY,
                shell.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'
            );
        });
    });
}

function bulkSelection() {
    const form = document.querySelector('[data-bulk-form]');
    if (!form) return;

    const bar   = form.querySelector('[data-bulk-bar]');
    const count = form.querySelector('[data-bulk-count]');
    const all   = form.querySelector('[data-bulk-all]');
    const items = () => [...form.querySelectorAll('[data-bulk-item]')];

    const sync = () => {
        const checked = items().filter((i) => i.checked).length;

        bar.hidden = checked === 0;
        count.textContent = `${checked} dipilih`;

        if (all) {
            all.checked = checked > 0 && checked === items().length;
            all.indeterminate = checked > 0 && checked < items().length;
        }
    };

    all?.addEventListener('change', () => {
        items().forEach((i) => { i.checked = all.checked; });
        sync();
    });

    items().forEach((i) => i.addEventListener('change', sync));
    sync();
}

function confirmDialog() {
    const modal = document.querySelector('[data-modal]');
    if (!modal) return;

    const titleEl = modal.querySelector('[data-modal-title]');
    const msgEl   = modal.querySelector('[data-modal-message]');
    const okBtn   = modal.querySelector('[data-modal-confirm]');

    let pending = null;

    const close = () => {
        modal.hidden = true;
        pending = null;
    };

    modal.querySelectorAll('[data-modal-close]').forEach((el) =>
        el.addEventListener('click', close)
    );

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) close();
    });

    okBtn.addEventListener('click', () => {
        if (pending) pending.submit();
        close();
    });

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            pending = form;
            titleEl.textContent = form.dataset.confirmTitle || 'Konfirmasi';
            msgEl.textContent   = form.dataset.confirmMessage || '';
            modal.hidden = false;
            okBtn.focus();
        });
    });
}

function uploadPreview() {
    document.querySelectorAll('[data-upload]').forEach((box) => {
        const input   = box.querySelector('[data-input]');
        const preview = box.querySelector('[data-preview]');
        const nameEl  = box.querySelector('[data-name]');

        if (!input) return;

        // Seret dan lepas
        ['dragenter', 'dragover'].forEach((ev) =>
            box.addEventListener(ev, (e) => {
                e.preventDefault();
                box.classList.add('is-dragging');
            })
        );

        ['dragleave', 'drop'].forEach((ev) =>
            box.addEventListener(ev, (e) => {
                e.preventDefault();
                box.classList.remove('is-dragging');
            })
        );

        box.addEventListener('drop', (e) => {
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });

        input.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;

            nameEl.textContent = `${file.name} — ${(file.size / 1024 / 1024).toFixed(2)} MB`;

            const reader = new FileReader();
            reader.onload = (e) => {
                preview.innerHTML = `<img src="${e.target.result}" alt="">`;
            };
            reader.readAsDataURL(file);
        });
    });
}

function autoEpisodeNumber() {
    const select = document.querySelector('[data-next-numbers]');
    const number = document.querySelector('[data-auto-number]');

    if (!select || !number) return;

    let map = {};
    try {
        map = JSON.parse(select.dataset.nextNumbers || '{}');
    } catch { return; }

    select.addEventListener('change', () => {
        // Jangan timpa nilai yang sudah diisi manusia.
        if (number.value && number.dataset.touched === 'true') return;

        number.value = map[select.value] ?? 1;
    });

    number.addEventListener('input', () => { number.dataset.touched = 'true'; });
}

function dismissToast() {
    document.querySelectorAll('[data-toast]').forEach((toast) => {
        setTimeout(() => toast.classList.add('is-hiding'), 4000);
        setTimeout(() => toast.remove(), 4400);
    });
}

export default function admin() {
    if (!document.body.classList.contains('admin-body')) return;

    sidebar();
    bulkSelection();
    confirmDialog();
    uploadPreview();
    autoEpisodeNumber();
    dismissToast();
    charts();
    reorderTable();
    videoUpload();
}

/**
 * Unggah video episode dengan progress bar.
 *
 * Memakai XMLHttpRequest, bukan fetch(), dan itu bukan pilihan gaya: fetch()
 * tidak menyediakan kemajuan PENGIRIMAN. Untuk berkas berukuran gigabyte,
 * halaman tanpa progress bar tidak bisa dibedakan dari halaman yang menggantung
 * — dan orang akan menutupnya di tengah jalan.
 */
function videoUpload() {
    const form = document.querySelector('[data-video-upload]');
    if (!form) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    const el = {
        drama:      form.querySelector('[data-drama]'),
        episode:    form.querySelector('[data-episode]'),
        note:       form.querySelector('[data-episode-note]'),
        title:      form.querySelector('[data-title]'),
        drop:       form.querySelector('[data-drop]'),
        file:       form.querySelector('[data-file]'),
        fileName:   form.querySelector('[data-file-name]'),
        facts:      form.querySelector('[data-facts]'),
        factName:   form.querySelector('[data-fact-name]'),
        factSize:   form.querySelector('[data-fact-size]'),
        factType:   form.querySelector('[data-fact-type]'),
        factTarget: form.querySelector('[data-fact-target]'),
        progress:   form.querySelector('[data-progress]'),
        bar:        form.querySelector('[data-progress-bar]'),
        label:      form.querySelector('[data-progress-label]'),
        result:     form.querySelector('[data-result]'),
        submit:     form.querySelector('[data-submit]'),
        providers:  form.querySelector('[data-provider]'),
        wrap:       form.querySelector('[data-provider-wrap]'),
    };

    const maxKb = Number(form.dataset.maxKb || 0);

    const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;

    const say = (text, kind) => {
        if (!el.result) return;
        el.result.hidden = false;
        el.result.className = `upload-result upload-result-${kind}`;
        el.result.textContent = text;
    };

    // --- Mode Auto / Manual ---
    const syncMode = () => {
        const manual = form.querySelector('[data-mode]:checked')?.value === 'manual';

        if (el.wrap) el.wrap.hidden = !manual;
        if (el.providers) el.providers.required = manual;

        updateTarget();
    };

    const updateTarget = () => {
        if (!el.factTarget) return;

        const manual = form.querySelector('[data-mode]:checked')?.value === 'manual';

        el.factTarget.textContent = manual
            ? (el.providers?.selectedOptions[0]?.textContent.trim() || 'belum dipilih')
            : 'Auto — provider default';
    };

    form.querySelectorAll('[data-mode]').forEach((r) =>
        r.addEventListener('change', syncMode)
    );
    el.providers?.addEventListener('change', updateTarget);

    // --- Drama -> daftar episode ---
    const loadEpisodes = async () => {
        const dramaId = el.drama?.value;

        el.episode.innerHTML = '';
        el.episode.disabled = true;

        if (!dramaId) {
            el.episode.innerHTML = '<option value="">— pilih drama dulu —</option>';
            return;
        }

        el.episode.innerHTML = '<option value="">memuat…</option>';

        // URL dibangun dengan drama=0 di Blade, lalu angkanya ditukar. Ini
        // menjaga agar nama route tetap satu-satunya sumber URL — tidak ada
        // path yang ditulis ulang di JavaScript.
        const url = (form.dataset.episodesUrl || '').replace(/\/0$/, `/${dramaId}`);

        try {
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!res.ok) throw new Error('gagal');

            const { data } = await res.json();

            if (!data.length) {
                el.episode.innerHTML = '<option value="">belum ada episode</option>';
                if (el.note) {
                    el.note.textContent =
                        'Drama ini belum punya episode. Buat episodenya dulu di menu Episode.';
                }
                return;
            }

            el.episode.innerHTML = '<option value="">— pilih episode —</option>';

            data.forEach((ep) => {
                const opt = document.createElement('option');
                opt.value = ep.id;
                opt.textContent = ep.has_video ? `${ep.label}  (sudah ada video)` : ep.label;
                opt.dataset.title = ep.title || '';
                opt.dataset.hasVideo = ep.has_video ? '1' : '';
                el.episode.appendChild(opt);
            });

            el.episode.disabled = false;

            if (el.note) {
                el.note.textContent =
                    'Episode yang sudah punya video akan DIGANTI, bukan ditambah.';
            }
        } catch {
            el.episode.innerHTML = '<option value="">gagal memuat</option>';
            say('Daftar episode gagal dimuat. Muat ulang halaman lalu coba lagi.', 'error');
        }
    };

    el.drama?.addEventListener('change', loadEpisodes);

    el.episode?.addEventListener('change', () => {
        const opt = el.episode.selectedOptions[0];

        // Judul diisi dari episode terpilih, tapi jangan timpa yang sudah
        // diketik manusia.
        if (el.title && !el.title.dataset.touched) {
            el.title.value = opt?.dataset.title || '';
        }

        if (opt?.dataset.hasVideo) {
            say('Episode ini sudah punya video. Mengunggah akan menggantinya, '
                + 'dan berkas lamanya dihapus dari penyimpanan.', 'warn');
        } else if (el.result) {
            el.result.hidden = true;
        }
    });

    el.title?.addEventListener('input', () => { el.title.dataset.touched = '1'; });

    // --- Seret dan lepas ---
    if (el.drop && el.file) {
        ['dragenter', 'dragover'].forEach((ev) =>
            el.drop.addEventListener(ev, (e) => {
                e.preventDefault();
                el.drop.classList.add('is-dragging');
            })
        );

        ['dragleave', 'drop'].forEach((ev) =>
            el.drop.addEventListener(ev, (e) => {
                e.preventDefault();
                el.drop.classList.remove('is-dragging');
            })
        );

        el.drop.addEventListener('drop', (e) => {
            if (e.dataTransfer.files.length) {
                el.file.files = e.dataTransfer.files;
                el.file.dispatchEvent(new Event('change'));
            }
        });
    }

    el.file?.addEventListener('change', () => {
        const file = el.file.files?.[0];

        if (!file) {
            if (el.facts) el.facts.hidden = true;
            return;
        }

        if (el.fileName) el.fileName.textContent = file.name;

        if (el.facts) el.facts.hidden = false;
        if (el.factName) el.factName.textContent = file.name;
        if (el.factSize) el.factSize.textContent = mb(file.size);
        if (el.factType) {
            el.factType.textContent =
                file.type || (file.name.split('.').pop() || '').toUpperCase();
        }

        updateTarget();

        // Diperiksa di sisi peramban lebih dulu supaya orang tidak menunggu
        // pengiriman berjam-jam untuk ditolak di ujung. Server tetap
        // memeriksanya sendiri — pemeriksaan di sini kesopanan, bukan penjagaan.
        if (maxKb > 0 && file.size / 1024 > maxKb) {
            say(`Berkas ${mb(file.size)} melewati batas `
                + `${(maxKb / 1024).toFixed(0)} MB di server ini.`, 'error');
        } else if (el.result) {
            el.result.hidden = true;
        }
    });

    // --- Kirim ---
    form.addEventListener('submit', (e) => {
        if (!token) return; // tanpa token, biarkan submit biasa berjalan

        e.preventDefault();

        const file = el.file?.files?.[0];

        if (!file) {
            say('Pilih berkas video lebih dulu.', 'error');
            return;
        }

        if (maxKb > 0 && file.size / 1024 > maxKb) {
            say(`Berkas ${mb(file.size)} melewati batas `
                + `${(maxKb / 1024).toFixed(0)} MB di server ini.`, 'error');
            return;
        }

        const xhr = new XMLHttpRequest();
        const body = new FormData(form);

        el.submit.disabled = true;
        el.submit.textContent = 'Mengunggah…';
        if (el.progress) el.progress.hidden = false;
        if (el.result) el.result.hidden = true;

        const setProgress = (percent, text) => {
            if (el.bar) {
                el.bar.style.width = `${percent}%`;
                el.bar.setAttribute('aria-valuenow', String(Math.round(percent)));
            }
            if (el.label) el.label.textContent = text;
        };

        xhr.upload.addEventListener('progress', (ev) => {
            if (!ev.lengthComputable) return;

            const percent = (ev.loaded / ev.total) * 100;

            setProgress(percent, `${percent.toFixed(0)}% — ${mb(ev.loaded)} dari ${mb(ev.total)}`);
        });

        // Pengiriman selesai bukan berarti selesai: server masih menghitung
        // checksum dan meneruskan berkas ke provider. Tanpa pesan ini, progress
        // bar berhenti di 100% dan terlihat seperti menggantung.
        xhr.upload.addEventListener('load', () => {
            setProgress(100, 'Terkirim. Server sedang menyimpan ke storage provider…');
        });

        const selesai = () => {
            el.submit.disabled = false;
            el.submit.textContent = 'Unggah video';
        };

        xhr.addEventListener('load', () => {
            selesai();

            let payload = null;
            try { payload = JSON.parse(xhr.responseText); } catch { /* bukan JSON */ }

            if (xhr.status >= 200 && xhr.status < 300 && payload?.ok) {
                setProgress(100, 'Selesai.');

                const d = payload.data || {};

                say(`${payload.message}  •  ${d.stored_filename || ''} `
                    + `(${d.size_human || ''}) • checksum ${String(d.checksum || '').slice(0, 12)}…`,
                    'success');

                el.file.value = '';
                if (el.facts) el.facts.hidden = true;
                if (el.fileName) {
                    el.fileName.textContent =
                        'Seret berkas ke sini, atau pilih lewat tombol di atas.';
                }
                return;
            }

            if (el.progress) el.progress.hidden = true;

            // 422 dari FormRequest membawa `errors`; dari StorageEngineException
            // membawa `message`. Keduanya perlu ditampilkan apa adanya.
            const pesan = payload?.message
                || Object.values(payload?.errors || {}).flat().join(' ')
                || `Unggahan gagal (HTTP ${xhr.status}).`;

            say(pesan, 'error');
        });

        xhr.addEventListener('error', () => {
            selesai();
            if (el.progress) el.progress.hidden = true;
            say('Koneksi terputus saat mengunggah. Berkas belum tersimpan — coba lagi.', 'error');
        });

        xhr.addEventListener('abort', () => {
            selesai();
            if (el.progress) el.progress.hidden = true;
            say('Unggahan dibatalkan.', 'warn');
        });

        xhr.open('POST', form.action);
        xhr.setRequestHeader('X-CSRF-TOKEN', token);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.send(body);
    });

    // Keadaan awal
    syncMode();
    if (el.drama?.value) loadEpisodes();
}

/**
 * Grafik dashboard.
 *
 * Chart.js dimuat dari CDN oleh layout admin dan tersedia sebagai global
 * `Chart`. Fungsi ini menunggu sampai skrip itu siap, lalu menggambar tiap
 * <canvas data-chart>. Bila CDN gagal dimuat, grafik dilewati tanpa
 * mengganggu bagian halaman lainnya.
 */
function charts() {
    const canvases = document.querySelectorAll('[data-chart]');
    if (!canvases.length) return;

    const draw = () => {
        if (typeof Chart === 'undefined') return false;

        const css = getComputedStyle(document.documentElement);
        const grid = 'rgba(255,255,255,.06)';
        const text = css.getPropertyValue('--text-muted').trim() || '#645E70';

        canvases.forEach((canvas) => {
            let labels = [];
            let values = [];

            try {
                labels = JSON.parse(canvas.dataset.labels || '[]');
                values = JSON.parse(canvas.dataset.values || '[]');
            } catch {
                return;
            }

            const color = canvas.dataset.color || '#D9AF6E';
            const money = canvas.dataset.money === '1';
            const type  = canvas.dataset.type || 'line';

            const fmt = (v) =>
                money
                    ? 'Rp ' + Number(v).toLocaleString('id-ID')
                    : Number(v).toLocaleString('id-ID');

            new Chart(canvas, {
                type,
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        borderColor: color,
                        backgroundColor: type === 'bar' ? color + '99' : color + '22',
                        borderWidth: 2,
                        fill: type === 'line',
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        borderRadius: type === 'bar' ? 3 : 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: { label: (ctx) => fmt(ctx.parsed.y) },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: text, font: { size: 10 }, maxRotation: 0, autoSkipPadding: 16 },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: grid },
                            ticks: { color: text, font: { size: 10 }, precision: 0, callback: fmt },
                        },
                    },
                },
            });
        });

        return true;
    };

    if (draw()) return;

    // Chart.js dimuat dengan atribut defer — coba lagi sampai siap.
    let tries = 0;
    const timer = setInterval(() => {
        if (draw() || ++tries > 40) clearInterval(timer);
    }, 100);
}

/**
 * Pengurutan baris tabel dengan seret-lepas.
 *
 * Memakai HTML5 Drag and Drop bawaan peramban — tanpa pustaka. Urutan
 * dikirim ke server setelah baris dilepas, dan bila gagal urutan
 * dikembalikan seperti semula supaya tampilan tidak berbohong.
 */
function reorderTable() {
    const table = document.querySelector('[data-reorder]');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const url = table.dataset.reorderUrl;
    const dramaId = table.dataset.dramaId;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!tbody || !url || !token) return;

    let dragged = null;
    let snapshot = null;

    tbody.addEventListener('dragstart', (e) => {
        const row = e.target.closest('tr[draggable]');
        if (!row) return;

        dragged = row;
        snapshot = [...tbody.querySelectorAll('tr')];
        row.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    tbody.addEventListener('dragover', (e) => {
        if (!dragged) return;
        e.preventDefault();

        const row = e.target.closest('tr[draggable]');
        if (!row || row === dragged) return;

        const box = row.getBoundingClientRect();
        const after = e.clientY > box.top + box.height / 2;

        tbody.insertBefore(dragged, after ? row.nextSibling : row);
    });

    tbody.addEventListener('dragend', async () => {
        if (!dragged) return;

        dragged.classList.remove('is-dragging');
        dragged = null;

        const ids = [...tbody.querySelectorAll('tr[data-id]')].map((r) => r.dataset.id);

        table.classList.add('is-saving');

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ drama_id: dramaId, ids }),
            });

            if (!res.ok) throw new Error('gagal');

            // Perbarui kolom nomor agar cocok dengan urutan baru.
            [...tbody.querySelectorAll('tr[data-id]')].forEach((row, i) => {
                const cell = row.querySelectorAll('td')[table.querySelector('[data-bulk-all]') ? 2 : 1];
                if (cell) cell.textContent = i + 1;
            });
        } catch {
            // Kembalikan urutan semula — jangan biarkan tampilan berbeda
            // dari isi database.
            snapshot?.forEach((row) => tbody.appendChild(row));
            alert('Gagal menyimpan urutan. Muat ulang halaman lalu coba lagi.');
        } finally {
            table.classList.remove('is-saving');
        }
    });
}
