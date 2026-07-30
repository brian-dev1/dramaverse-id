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
    assetManager();
    uploadQueue();
}

/**
 * Halaman Upload Queue.
 *
 * Dua pekerjaan: membuka rincian beserta log satu pekerjaan, dan menyegarkan
 * badge status baris yang belum selesai.
 *
 * Yang SENGAJA tidak dilakukan: memuat ulang halaman sendiri ketika ada
 * pekerjaan yang selesai. Admin bisa sedang mengetik di kotak pencarian atau
 * membaca log yang sedang terbuka, dan halaman yang tiba-tiba berganti akan
 * membuang keduanya. Yang muncul hanyalah catatan bahwa ada yang berubah,
 * beserta tautan untuk memuat ulang bila memang diinginkan.
 */
function uploadQueue() {
    const table = document.querySelector('[data-upload-queue]');
    if (!table) return;

    // Route dibangun di Blade dengan uuid nol sebagai penampung, lalu
    // ditukar di sini. Nama route tetap menjadi satu-satunya sumber URL —
    // tidak ada path yang ditulis ulang di JavaScript.
    const PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
    const template = table.dataset.statusUrl || '';

    const urlFor = (uuid) => template.replace(PLACEHOLDER, uuid);

    const panel = document.querySelector('[data-detail-panel]');
    const el = {
        title: panel?.querySelector('[data-detail-title]'),
        meta:  panel?.querySelector('[data-detail-meta]'),
        error: panel?.querySelector('[data-detail-error]'),
        log:   panel?.querySelector('[data-detail-log]'),
        close: panel?.querySelector('[data-detail-close]'),
    };

    const ambil = async (uuid) => {
        const res = await fetch(urlFor(uuid), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        return res.json();
    };

    // --- Rincian ---
    table.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-detail]');
        if (!btn || !panel) return;

        el.title.textContent = 'Memuat rincian…';
        el.meta.textContent = '';
        el.error.hidden = true;
        el.log.innerHTML = '';
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
            const { data, logs } = await ambil(btn.dataset.detail);

            el.title.textContent = data.filename;

            el.meta.textContent =
                `${data.episode} — ${data.storage} — ${data.size_human} — `
                + `${data.status_text}, percobaan ${data.attempts} dari ${data.max_attempts}`
                + (data.duration_ms ? ` — ${(data.duration_ms / 1000).toFixed(1)} detik` : '');

            if (data.error) {
                el.error.hidden = false;
                el.error.textContent = data.error;
            }

            (logs || []).forEach((entry) => {
                const li = document.createElement('li');
                li.className = `queue-log-item ${entry.class}`;

                const waktu = document.createElement('span');
                waktu.className = 'queue-log-time';
                waktu.textContent = entry.at || '';

                const teks = document.createElement('span');
                teks.textContent = `${entry.event}: ${entry.message || '(tanpa pesan)'}`;

                li.appendChild(waktu);
                li.appendChild(teks);
                el.log.appendChild(li);
            });

            if (!logs || !logs.length) {
                const li = document.createElement('li');
                li.className = 'queue-log-item log-info';
                li.textContent = 'Belum ada catatan untuk pekerjaan ini.';
                el.log.appendChild(li);
            }
        } catch (err) {
            el.title.textContent = 'Rincian gagal dimuat';
            el.error.hidden = false;
            el.error.textContent =
                'Muat ulang halaman lalu coba lagi. Penyebab: ' + err.message;
        }
    });

    el.close?.addEventListener('click', () => { panel.hidden = true; });

    // --- Segarkan badge baris yang belum selesai ---
    let catatan = null;

    const beriTahuBerubah = () => {
        if (catatan) return;

        catatan = document.createElement('p');
        catatan.className = 'queue-note';
        catatan.textContent =
            'Ada pekerjaan yang berubah status. Muat ulang halaman supaya tombol '
            + 'aksinya ikut menyesuaikan.';

        const tautan = document.createElement('a');
        tautan.href = window.location.href;
        tautan.className = 'btn btn-ghost btn-sm';
        tautan.textContent = 'Muat ulang';

        catatan.appendChild(tautan);
        table.parentNode.insertBefore(catatan, table);
    };

    const segarkan = async () => {
        const rows = [...table.querySelectorAll('tr[data-job]')]
            .filter((row) => !row.dataset.final);

        if (!rows.length) {
            clearInterval(timer);
            return;
        }

        for (const row of rows) {
            try {
                const { data } = await ambil(row.dataset.job);

                const cell = row.querySelector('[data-status-cell]');

                if (cell && cell.textContent.trim() !== data.status_text) {
                    cell.textContent = data.status_text;
                    cell.className = `badge badge-status ${data.badge}`;
                }

                if (data.final) {
                    row.dataset.final = '1';
                    beriTahuBerubah();
                }
            } catch {
                // Satu baris yang gagal ditanyakan tidak boleh menghentikan
                // yang lain. Percobaan berikutnya akan menanyakannya lagi.
            }
        }
    };

    const timer = setInterval(segarkan, 4000);
}

/**
 * Asset Manager drama.
 *
 * Satu kartu per jenis aset, masing-masing dengan seret-lepas, pratayang,
 * progress, dan tombol hapus sendiri. Semuanya berbagi satu pilihan Storage
 * Mode di atas halaman.
 *
 * Memakai XMLHttpRequest dengan alasan yang sama seperti unggah video: fetch()
 * tidak menyediakan kemajuan PENGIRIMAN, dan galeri berisi dua puluh gambar
 * membutuhkan waktu yang cukup lama untuk terlihat seperti menggantung.
 */
function assetManager() {
    const root = document.querySelector('[data-asset-manager]');
    if (!root) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;

    const storeUrl  = root.dataset.storeUrl;
    const deleteUrl = root.dataset.deleteUrl || '';

    const providerWrap = root.querySelector('[data-provider-wrap]');
    const providerSel  = root.querySelector('[data-provider]');

    const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;

    const currentMode = () =>
        root.querySelector('[data-mode]:checked')?.value === 'manual' ? 'manual' : 'auto';

    // --- Storage mode ---
    const syncMode = () => {
        if (providerWrap) providerWrap.hidden = currentMode() !== 'manual';
    };

    root.querySelectorAll('[data-mode]').forEach((r) =>
        r.addEventListener('change', syncMode)
    );

    syncMode();

    // --- Tiap kartu ---
    root.querySelectorAll('[data-asset-card]').forEach((card) => {
        const type     = card.dataset.type;
        const multiple = card.dataset.multiple === '1';
        const maxKb    = Number(card.dataset.maxKb || 0);

        const el = {
            drop:     card.querySelector('[data-drop]'),
            file:     card.querySelector('[data-file]'),
            fileName: card.querySelector('[data-file-name]'),
            preview:  card.querySelector('[data-preview]'),
            progress: card.querySelector('[data-progress]'),
            bar:      card.querySelector('[data-progress-bar]'),
            label:    card.querySelector('[data-progress-label]'),
            result:   card.querySelector('[data-result]'),
            submit:   card.querySelector('[data-submit]'),
            items:    card.querySelector('[data-items]'),
            count:    card.querySelector('[data-count]'),
        };

        const say = (text, kind) => {
            if (!el.result) return;
            el.result.hidden = false;
            el.result.className = `upload-result upload-result-${kind}`;
            el.result.textContent = text;
        };

        const clearSay = () => { if (el.result) el.result.hidden = true; };

        const refreshCount = () => {
            if (el.count) {
                el.count.textContent = String(el.items.querySelectorAll('[data-item]').length);
            }
            const empty = el.items.querySelector('[data-empty]');
            const ada   = el.items.querySelectorAll('[data-item]').length > 0;
            if (empty) empty.hidden = ada;
        };

        // --- Pratayang sebelum unggah ---
        const renderPreview = (files) => {
            if (!el.preview) return;

            el.preview.innerHTML = '';

            if (!files.length) {
                el.preview.hidden = true;
                return;
            }

            el.preview.hidden = false;

            [...files].forEach((file) => {
                const box = document.createElement('div');
                box.className = 'asset-preview-item';

                const isImage = file.type.startsWith('image/');

                // Gambar dapat thumbnail; subtitle hanya nama, ukuran, format —
                // membaca berkas teks sebagai data URL tidak memberi apa pun
                // yang berguna untuk dilihat.
                if (isImage) {
                    const img = document.createElement('img');
                    img.alt = file.name;

                    // Objek URL dilepas setelah gambar dimuat. Tanpa itu,
                    // memilih berkas berkali-kali membocorkan memori peramban.
                    const url = URL.createObjectURL(file);
                    img.src = url;
                    img.onload = () => URL.revokeObjectURL(url);

                    box.appendChild(img);
                } else {
                    const ph = document.createElement('span');
                    ph.className = 'asset-preview-file';
                    ph.textContent = (file.name.split('.').pop() || '?').toUpperCase();
                    box.appendChild(ph);
                }

                const meta = document.createElement('p');
                meta.className = 'asset-preview-meta';
                meta.textContent = `${file.name} — ${mb(file.size)}`;
                box.appendChild(meta);

                el.preview.appendChild(box);
            });
        };

        const onPick = () => {
            const files = el.file.files;

            el.submit.disabled = !files.length;

            if (!files.length) {
                el.fileName.textContent = 'Seret ke sini, atau pilih lewat tombol.';
                renderPreview([]);
                return;
            }

            el.fileName.textContent = files.length === 1
                ? files[0].name
                : `${files.length} berkas dipilih`;

            renderPreview(files);

            // Diperiksa di peramban lebih dulu supaya orang tidak menunggu
            // pengiriman untuk ditolak di ujung. Server tetap memeriksanya
            // sendiri — ini kesopanan, bukan penjagaan.
            const kebesaran = [...files].filter((f) => maxKb > 0 && f.size / 1024 > maxKb);

            if (kebesaran.length) {
                say(`${kebesaran.length} berkas melewati batas `
                    + `${(maxKb / 1024).toFixed(1)} MB.`, 'error');
            } else {
                clearSay();
            }
        };

        el.file?.addEventListener('change', onPick);

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
                if (!e.dataTransfer.files.length) return;

                // DataTransfer dipakai supaya input menerima banyak berkas
                // sekaligus, dan supaya jenis tunggal hanya mengambil satu.
                const dt = new DataTransfer();
                const files = [...e.dataTransfer.files].slice(0, multiple ? 20 : 1);
                files.forEach((f) => dt.items.add(f));

                el.file.files = dt.files;
                onPick();
            });
        }

        // --- Unggah ---
        el.submit?.addEventListener('click', () => {
            const files = el.file?.files;
            if (!files?.length) return;

            if (currentMode() === 'manual' && !providerSel?.value) {
                say('Mode Manual dipilih, tapi provider belum ditentukan.', 'error');
                return;
            }

            const body = new FormData();
            body.append('asset_type', type);
            body.append('storage_mode', currentMode());

            if (currentMode() === 'manual') {
                body.append('storage_provider_id', providerSel.value);
            }

            [...files].forEach((f) => body.append('files[]', f));

            const xhr = new XMLHttpRequest();

            el.submit.disabled = true;
            el.submit.textContent = 'Mengunggah…';
            if (el.progress) el.progress.hidden = false;
            clearSay();

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

            // Terkirim bukan berarti selesai: server masih menghitung checksum
            // dan meneruskan ke provider. Tanpa pesan ini, bar berhenti di 100%
            // dan terlihat menggantung.
            xhr.upload.addEventListener('load', () => {
                setProgress(100, 'Terkirim. Server sedang menyimpan ke storage provider…');
            });

            const selesai = () => {
                el.submit.disabled = false;
                el.submit.textContent = card.querySelector('[data-item]') && !multiple
                    ? 'Ganti' : 'Unggah';
            };

            xhr.addEventListener('load', () => {
                selesai();

                let payload = null;
                try { payload = JSON.parse(xhr.responseText); } catch { /* bukan JSON */ }

                if (xhr.status >= 200 && xhr.status < 300 && payload?.ok) {
                    setProgress(100, 'Selesai.');

                    if (!multiple) el.items.innerHTML = '';

                    (payload.data || []).forEach((a) => el.items.appendChild(buildItem(a)));

                    refreshCount();

                    el.file.value = '';
                    renderPreview([]);
                    el.fileName.textContent = 'Seret ke sini, atau pilih lewat tombol.';
                    el.submit.disabled = true;

                    const gagal = payload.gagal || [];
                    say(payload.message + (gagal.length
                        ? ' ' + gagal.map((g) => `${g.nama}: ${g.pesan}`).join(' | ')
                        : ''), gagal.length ? 'warn' : 'success');

                    setTimeout(() => { if (el.progress) el.progress.hidden = true; }, 1200);
                    return;
                }

                if (el.progress) el.progress.hidden = true;

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

            xhr.open('POST', storeUrl);
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(body);
        });

        // --- Hapus ---
        el.items?.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-delete]');
            if (!btn) return;

            const item = btn.closest('[data-item]');
            const id = item?.dataset.id;
            if (!id) return;

            if (!window.confirm('Hapus aset ini? Berkasnya ikut dihapus dari storage.')) return;

            btn.disabled = true;

            try {
                // DELETE sungguhan, bukan POST + _method.
                //
                // Laravel hanya membaca method spoofing `_method` dari body
                // ber-form-encoding; pada body JSON nilainya tidak pernah
                // sampai ke Request, sehingga permintaannya tetap POST dan
                // route DELETE membalas 405. Mengirim DELETE langsung
                // menghindari seluruh persoalan itu.
                const res = await fetch(deleteUrl.replace(/\/0$/, `/${id}`), {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                });

                const payload = await res.json().catch(() => null);

                if (!res.ok || !payload?.ok) {
                    throw new Error(payload?.message || 'gagal');
                }

                item.remove();
                refreshCount();
                say(payload.message, 'success');

                if (!multiple) {
                    el.submit.textContent = 'Unggah';
                }
            } catch (err) {
                btn.disabled = false;
                say(err.message || 'Aset gagal dihapus.', 'error');
            }
        });

        // --- Membangun satu baris aset dari respons JSON ---
        function buildItem(a) {
            const art = document.createElement('article');
            art.className = 'asset-item';
            art.dataset.item = '';
            art.dataset.id = a.id;

            const thumb = document.createElement('div');
            thumb.className = 'asset-thumb';

            if (a.previewable && a.public_url) {
                const img = document.createElement('img');
                img.src = a.public_url;
                img.alt = a.original_filename;
                img.loading = 'lazy';
                thumb.appendChild(img);
            } else {
                const ph = document.createElement('span');
                ph.className = 'asset-thumb-empty';
                ph.textContent = (a.extension || '?').toUpperCase();
                thumb.appendChild(ph);
            }

            const meta = document.createElement('div');
            meta.className = 'asset-meta';

            const name = document.createElement('p');
            name.className = 'asset-name';
            name.title = a.original_filename;
            name.textContent = a.original_filename;

            const sub = document.createElement('p');
            sub.className = 'asset-sub';
            sub.textContent = `${a.size_human} · ${(a.extension || '?').toUpperCase()} · ${a.provider || '—'}`;

            const sub2 = document.createElement('p');
            sub2.className = 'asset-sub';
            sub2.textContent = `checksum ${a.checksum_short}…`;

            meta.append(name, sub, sub2);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn-icon btn-danger';
            del.dataset.delete = '';
            del.title = 'Hapus';
            del.setAttribute('aria-label', 'Hapus aset');
            del.textContent = '×';

            art.append(thumb, meta, del);

            return art;
        }

        refreshCount();
    });
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
        queue:      form.querySelector('[data-queue-status]'),
        queueBadge: form.querySelector('[data-queue-badge]'),
        queueText:  form.querySelector('[data-queue-text]'),
    };

    const maxKb = Number(form.dataset.maxKb || 0);

    const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;

    const say = (text, kind) => {
        if (!el.result) return;
        el.result.hidden = false;
        el.result.className = `upload-result upload-result-${kind}`;
        el.result.textContent = text;
    };

    /*
     * --- Pengamat antrean (Sprint 7.7) ---
     *
     * Sejak unggahan diantrekan, respons server datang JAUH sebelum berkasnya
     * sampai ke storage provider. Tanpa bagian ini, halaman akan mengatakan
     * "berhasil" pada saat yang belum ada satu byte pun terkirim ke bucket —
     * dan kegagalan di worker tidak akan pernah terlihat oleh yang mengunggah.
     *
     * Polling berhenti sendiri begitu statusnya final, dan menyerah setelah
     * lima kegagalan berturut-turut supaya tab yang ditinggalkan terbuka tidak
     * memanggil server selamanya.
     */
    let pollTimer = null;

    const showQueue = (badge, text, kelas) => {
        if (!el.queue) return;

        el.queue.hidden = false;

        if (el.queueBadge) {
            el.queueBadge.className = `badge badge-status ${kelas || 'badge-pending'}`;
            el.queueBadge.textContent = badge;
        }

        if (el.queueText) el.queueText.textContent = text;
    };

    const pesanUntuk = (data) => {
        if (data.status === 'pending') {
            return 'Menunggu worker mengambil pekerjaannya. Halaman ini boleh ditutup.';
        }
        if (data.status === 'processing') {
            return 'Worker sedang mengirim berkas ke storage provider.';
        }
        if (data.status === 'success') {
            return `Selesai dalam ${((data.duration_ms || 0) / 1000).toFixed(1)} detik.`;
        }
        if (data.status === 'cancelled') {
            return 'Dibatalkan sebelum diproses.';
        }
        return data.error || 'Gagal tanpa pesan. Buka Upload Queue untuk log lengkapnya.';
    };

    const watchJob = (url) => {
        clearInterval(pollTimer);

        showQueue('Menunggu', 'Menunggu worker mengambil pekerjaannya.', 'badge-pending');

        let gagalBerturut = 0;

        const tanya = async () => {
            try {
                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const { data } = await res.json();

                gagalBerturut = 0;

                showQueue(data.status_text, pesanUntuk(data), data.badge);

                if (!data.final) return;

                clearInterval(pollTimer);

                if (data.status === 'success') {
                    setProgressBar(100, 'Selesai. Video sudah ada di storage provider.');
                    say('Video tersimpan di storage provider.', 'success');
                } else {
                    say(pesanUntuk(data), data.status === 'cancelled' ? 'warn' : 'error');
                }
            } catch (err) {
                if (++gagalBerturut < 5) return;

                clearInterval(pollTimer);

                showQueue(
                    'Tidak diketahui',
                    'Status pekerjaan tidak bisa ditanyakan. Buka Upload Queue untuk memeriksanya.',
                    'badge-off'
                );
            }
        };

        tanya();
        pollTimer = setInterval(tanya, 3000);
    };

    // Progress bar dipakai dua tempat — saat mengirim, dan saat pekerjaan
    // selesai di antrean — jadi pengaturnya diangkat ke sini.
    const setProgressBar = (percent, text) => {
        if (el.bar) {
            el.bar.style.width = `${percent}%`;
            el.bar.setAttribute('aria-valuenow', String(Math.round(percent)));
        }
        if (el.label) el.label.textContent = text;
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

        clearInterval(pollTimer);

        el.submit.disabled = true;
        el.submit.textContent = 'Mengunggah…';
        if (el.progress) el.progress.hidden = false;
        if (el.result) el.result.hidden = true;
        if (el.queue) el.queue.hidden = true;

        const setProgress = setProgressBar;

        xhr.upload.addEventListener('progress', (ev) => {
            if (!ev.lengthComputable) return;

            const percent = (ev.loaded / ev.total) * 100;

            setProgress(percent, `${percent.toFixed(0)}% — ${mb(ev.loaded)} dari ${mb(ev.total)}`);
        });

        // Pengiriman selesai bukan berarti selesai. Sejak Sprint 7.7 yang
        // terjadi berikutnya bukan lagi penyimpanan ke provider, melainkan
        // penyimpanan sementara di server dan pendaftaran ke antrean — jauh
        // lebih cepat, tapi tetap bukan nol.
        xhr.upload.addEventListener('load', () => {
            setProgress(100, 'Terkirim ke server. Mendaftarkan ke antrean…');
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
                const d = payload.data || {};

                setProgress(100, 'Masuk antrean. Pengiriman ke storage provider berjalan di latar belakang.');

                // Pesannya sengaja TIDAK berbunyi "tersimpan". Pada titik ini
                // berkasnya baru sampai di server; yang tersimpan di storage
                // provider masih nol byte.
                say(payload.message, 'success');

                el.file.value = '';
                if (el.facts) el.facts.hidden = true;
                if (el.fileName) {
                    el.fileName.textContent =
                        'Seret berkas ke sini, atau pilih lewat tombol di atas.';
                }

                if (d.status_url) watchJob(d.status_url);

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
