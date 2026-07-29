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
