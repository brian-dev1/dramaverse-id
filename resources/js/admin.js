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
}
