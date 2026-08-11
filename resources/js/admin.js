/**
 * Panel admin: sidebar, aksi massal, dialog konfirmasi, pratinjau unggahan,
 * nomor episode otomatis, dan toast yang menghilang sendiri.
 */

const SIDEBAR_KEY = 'dv-admin-sidebar';

function sidebar() {
    const shell = document.querySelector('[data-shell]');
    if (!shell) return;

    // Di ponsel menu selalu mulai tertutup agar konten langsung terlihat.
    const isMobile = window.matchMedia('(max-width: 900px)').matches;

    if (isMobile || localStorage.getItem(SIDEBAR_KEY) === 'collapsed') {
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

    // Checkbox baris tidak lagi berada di dalam form ini — form ini
    // sengaja tidak melingkupi tabel (lihat komentar di Blade-nya), dan
    // checkbox-nya menempel lewat atribut form="bulk-form". Jadi
    // pencariannya lewat document, bukan lewat form.querySelector.
    const bar   = form.querySelector('[data-bulk-bar]');
    const count = form.querySelector('[data-bulk-count]');
    const all   = document.querySelector('[data-bulk-all]');
    const items = () => [...document.querySelectorAll('[data-bulk-item]')];

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

            /*
            | Label tombolnya mengikuti form, bukan dipatok "Hapus".
            |
            | Dialog ini semula hanya melayani penghapusan, jadi tombolnya
            | ditulis merah bertuliskan Hapus. Begitu ia dipakai untuk tindakan
            | lain — mengirim postingan ke channel, misalnya — yang dibaca
            | admin adalah tombol merah bertuliskan Hapus untuk tindakan yang
            | tidak menghapus apa pun. Orang yang membacanya dengan benar akan
            | menekan Batal.
            |
            | Warnanya ikut menyesuaikan: merah hanya untuk yang merusak.
            */
            okBtn.textContent = form.dataset.confirmOk || 'Hapus';

            const merusak = !form.dataset.confirmOk;

            okBtn.classList.toggle('btn-danger', merusak);
            okBtn.classList.toggle('btn-primary', !merusak);

            modal.hidden = false;
            okBtn.focus();
        });
    });
}

/**
 * Template channel siap pakai.
 *
 * Menempelkan isi template ke kolom-kolomnya. TIDAK menyimpan: penyimpanan
 * tetap lewat tombol Simpan yang sama dengan pengaturan lain, supaya tidak
 * ada dua jalur simpan untuk satu kolom — dan supaya admin masih bisa
 * menyunting hasilnya, atau membatalkannya dengan menutup halaman.
 */
function templatePicker() {
    const tombol = document.querySelectorAll('[data-tpl]');

    if (!tombol.length) return;

    const isi = (nama, nilai) => {
        const el = document.getElementById('field-' + nama);

        if (!el) return;

        el.value = nilai;

        // Beri tahu peramban dan skrip lain bahwa isinya berubah — kalau
        // tidak, textarea yang tingginya menyesuaikan isi tidak ikut tumbuh.
        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    tombol.forEach((b) => {
        b.addEventListener('click', () => {
            isi('channel_template', b.dataset.tplTemplate);
            isi('channel_line', b.dataset.tplBaris);
            isi('channel_free_mark', b.dataset.tplGratis);
            isi('channel_vip_mark', b.dataset.tplVip);

            document.querySelectorAll('[data-tpl]').forEach((lain) => {
                lain.classList.toggle('btn-primary', lain === b);
            });

            const kolom = document.getElementById('field-channel_template');

            if (kolom) kolom.scrollIntoView({ behavior: 'smooth', block: 'center' });
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

    // Pada form tambah massal, nomor otomatis itu adalah kolom "Dari nomor".
    // Kolom "Sampai nomor" di baris yang sama harus ikut bergerak: rentang
    // 12–5 yang tertinggal dari drama sebelumnya bukan nilai awal yang masuk
    // akal, dan validasinya baru menolak setelah admin menekan Simpan.
    const row = number.closest('[data-range-row]');
    const until = row?.querySelector('input[name$="[to]"]') ?? null;

    select.addEventListener('change', () => {
        // Jangan timpa nilai yang sudah diisi manusia.
        if (number.value && number.dataset.touched === 'true') return;

        const next = map[select.value] ?? 1;

        number.value = next;

        if (until && until.dataset.touched !== 'true') {
            until.value = next;
        }
    });

    until?.addEventListener('input', () => { until.dataset.touched = 'true'; });

    number.addEventListener('input', () => { number.dataset.touched = 'true'; });
}

/**
 * Baris rentang pada form tambah episode massal.
 *
 * Nama input diberi indeks ulang setiap kali baris ditambah atau dihapus.
 * Kalau tidak, menghapus baris tengah meninggalkan lubang pada indeks
 * (ranges[0], ranges[2]) — PHP masih membacanya, tapi pesan error validasi
 * jadi menunjuk baris yang salah di mata admin.
 */
function episodeRanges() {
    const table = document.querySelector('[data-range-table]');

    if (!table) return;

    const body = table.querySelector('[data-range-body]');
    const add  = document.querySelector('[data-range-add]');

    const reindex = () => {
        body.querySelectorAll('[data-range-row]').forEach((row, i) => {
            row.querySelectorAll('input, select').forEach((el) => {
                el.name = el.name.replace(/ranges\[\d+\]/, `ranges[${i}]`);
            });
        });
    };

    add?.addEventListener('click', () => {
        const rows = body.querySelectorAll('[data-range-row]');
        const last = rows[rows.length - 1];

        if (!last) return;

        const clone = last.cloneNode(true);

        // Pesan error milik baris sumber tidak ikut disalin.
        clone.querySelectorAll('.field-error').forEach((el) => el.remove());
        clone.querySelectorAll('[data-auto-number]').forEach((el) => {
            delete el.dataset.autoNumber;
        });

        // Rentang baru dimulai satu nomor setelah rentang terakhir.
        const lastTo = parseInt(last.querySelector('input[name$="[to]"]')?.value, 10);
        const inputs = clone.querySelectorAll('input[type="number"]');

        if (!Number.isNaN(lastTo)) {
            inputs[0].value = lastTo + 1;
            inputs[1].value = lastTo + 1;
        }

        body.appendChild(clone);
        reindex();
        inputs[0].focus();
    });

    body.addEventListener('click', (e) => {
        if (!e.target.closest('[data-range-remove]')) return;

        // Baris terakhir tidak boleh hilang: form tanpa rentang tidak bisa
        // dikirim, dan admin akan terjebak tanpa tombol untuk memulihkannya.
        if (body.querySelectorAll('[data-range-row]').length <= 1) return;

        e.target.closest('[data-range-row]').remove();
        reindex();
    });
}

function dismissToast() {
    document.querySelectorAll('[data-toast]').forEach((toast) => {
        setTimeout(() => toast.classList.add('is-hiding'), 4000);
        setTimeout(() => toast.remove(), 4400);
    });
}

/**
 * Menu bergembok: jelaskan kenapa tidak terbuka.
 *
 * Tombol yang diklik lalu tidak terjadi apa-apa membuat orang mengira
 * panelnya rusak, dan lima menit kemudian mengirim pesan menanyakannya.
 * Satu kalimat di sini menjawab pertanyaan itu sebelum sempat ditanyakan.
 *
 * Ini murni penjelasan di layar. Penjagaannya sendiri ada di middleware izin
 * pada route — mengetik URL-nya langsung tetap ditolak 403.
 */
function lockedMenu() {
    const nav = document.querySelector('.admin-nav');

    if (!nav) return;

    nav.addEventListener('click', (e) => {
        const tombol = e.target.closest('[data-locked]');

        if (!tombol) return;

        e.preventDefault();

        const label = tombol.dataset.lockedLabel || 'Menu ini';

        showToast(
            `${label} hanya bisa dibuka Super Admin. Hubungi Super Admin bila Anda memerlukan aksesnya.`
        );
    });
}

/**
 * Toast yang dibuat dari JavaScript, untuk pesan yang tidak lewat session.
 *
 * Memakai kelas dan atribut yang sama dengan toast dari server supaya
 * tampilannya identik dan `dismissToast()` di atas tidak perlu tahu asalnya.
 */
function showToast(pesan) {
    const induk = document.querySelector('.admin-main') || document.body;

    // Ganti toast terkunci yang masih tampil, jangan menumpuknya. Mengklik
    // beberapa menu terkunci berturut-turut adalah hal yang wajar dilakukan
    // orang yang sedang mencari tahu mana yang boleh dibuka.
    induk.querySelector('[data-toast-locked]')?.remove();

    const toast = document.createElement('div');

    toast.className = 'toast toast-error';
    toast.setAttribute('role', 'alert');
    toast.dataset.toast = '';
    toast.dataset.toastLocked = '';
    toast.textContent = pesan;

    const topbar = induk.querySelector('.admin-topbar');

    topbar ? topbar.after(toast) : induk.prepend(toast);

    setTimeout(() => toast.classList.add('is-hiding'), 4000);
    setTimeout(() => toast.remove(), 4400);
}

export default function admin() {
    if (!document.body.classList.contains('admin-body')) return;

    sidebar();
    bulkSelection();
    confirmDialog();
    uploadPreview();
    autoEpisodeNumber();
    episodeRanges();
    dismissToast();
    lockedMenu();
    templatePicker();
    charts();
    reorderTable();
    videoUpload();
    assetManager();
    uploadQueue();

    // Sprint 7.8 dan 7.9. Ketiganya berhenti sendiri di baris pertama bila
    // elemen penandanya tidak ada, jadi memanggilnya di setiap halaman admin
    // tidak menimbulkan biaya apa pun.
    storageMonitor();
    fileManager();
    batchUpload();
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

/*
|==============================================================================
| Sprint 7.8 — Storage Monitoring
|==============================================================================
*/

/**
 * Tombol Refresh Status di halaman Storage Monitoring.
 *
 * Yang disegarkan hanya ANGKA — kartu ringkasan dan empat kolom di tabel yang
 * memang bisa berubah tanpa jumlah barisnya berubah. Halaman tidak dimuat
 * ulang, dan itu disengaja: hasil Test Connection yang sedang dibaca ada di
 * panel yang menetap, dan memuat ulang halaman akan membuangnya justru ketika
 * pesannya paling perlu dibaca.
 *
 * Kalau JUMLAH provider berubah, angka di kartu ikut berubah tetapi barisnya
 * tidak — menyusun ulang seluruh tabel dari JavaScript berarti menulis markup
 * baris untuk kedua kalinya di tempat yang berbeda dari Blade, dan dua salinan
 * markup adalah cara paling mudah membuat keduanya berbeda. Yang muncul adalah
 * catatan beserta tautan untuk memuat ulang.
 */
function storageMonitor() {
    const root = document.querySelector('[data-monitor]');
    if (!root) return;

    const url = root.dataset.refreshUrl;
    if (!url) return;

    const tombol = root.querySelector('[data-monitor-refresh]');
    const waktu  = root.querySelector('[data-monitor-at]');

    const ambilJalur = (obj, jalur) =>
        jalur.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);

    const angka = (v) =>
        typeof v === 'number' ? v.toLocaleString('id-ID') : String(v ?? '—');

    let catatan = null;

    const beriTahuBerubah = () => {
        if (catatan) return;

        catatan = document.createElement('p');
        catatan.className = 'queue-note';
        catatan.textContent =
            'Jumlah providernya berubah. Muat ulang halaman supaya tabelnya ikut '
            + 'menyesuaikan.';

        const tautan = document.createElement('a');
        tautan.href = window.location.href;
        tautan.className = 'btn btn-ghost btn-sm';
        tautan.textContent = 'Muat ulang';

        catatan.appendChild(tautan);
        root.parentNode.insertBefore(catatan, root.nextSibling);
    };

    tombol?.addEventListener('click', async () => {
        tombol.disabled = true;

        const teksAsli = tombol.textContent;
        tombol.textContent = 'Menyegarkan…';

        try {
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const { data } = await res.json();

            document.querySelectorAll('[data-monitor-value]').forEach((el) => {
                el.textContent = angka(ambilJalur(data, el.dataset.monitorValue));
            });

            if (waktu) waktu.textContent = data.at || '';

            const barisTampil = document.querySelectorAll('[data-monitor-row]').length;

            (data.rows || []).forEach((row) => {
                const tr = document.querySelector(`[data-monitor-row="${row.id}"]`);
                if (!tr) return;

                const badge = tr.querySelector('[data-monitor-test]');
                if (badge) {
                    badge.textContent = row.test_label;
                    badge.className = `badge badge-status ${row.test_badge}`;
                }

                const diuji = tr.querySelector('[data-monitor-tested]');
                if (diuji) {
                    diuji.textContent = row.tested_at || '—';

                    if (row.duration) {
                        const sub = document.createElement('span');
                        sub.className = 'asset-sub';
                        sub.textContent = row.duration;
                        diuji.appendChild(sub);
                    }
                }

                const berkas = tr.querySelector('[data-monitor-files]');
                if (berkas) berkas.textContent = angka(row.files);

                const ukuran = tr.querySelector('[data-monitor-size]');
                if (ukuran) ukuran.textContent = row.size_human;
            });

            if ((data.rows || []).length !== barisTampil) {
                beriTahuBerubah();
            }
        } catch (err) {
            // Kegagalan menyegarkan tidak boleh menghapus angka yang sudah
            // tampil. Yang lama tetap benar sampai detik terakhir ia dibaca;
            // menggantinya dengan tanda tanya justru menghilangkan informasi.
            if (waktu) {
                waktu.textContent = `gagal disegarkan (${err.message}) — angka di atas dari pemuatan terakhir`;
            }
        } finally {
            tombol.disabled = false;
            tombol.textContent = teksAsli;
        }
    });
}

/*
|==============================================================================
| Sprint 7.8 — File Manager
|==============================================================================
*/

/**
 * Halaman File Manager.
 *
 * Tiga pekerjaan: membuka rincian beserta pratayang gambar, menyalin URL, dan
 * mengarahkan dua formulir (ganti nama, pindahkan) ke berkas yang dipilih.
 *
 * URL formulir TIDAK disusun di sini. Blade merender action-nya dengan
 * penampung (`episode_video/0`), dan yang dilakukan JavaScript hanya menukar
 * penampung itu dengan sumber dan id yang sebenarnya. Dengan begitu nama route
 * tetap menjadi satu-satunya sumber URL, dan mengubah prefix route tidak
 * meninggalkan path yang salah di dalam JavaScript.
 */
function fileManager() {
    const table = document.querySelector('[data-file-manager]');
    if (!table) return;

    const PENAMPUNG = 'episode_video/0';

    const template = table.dataset.showUrl || '';

    // "episode_video:12" -> "episode_video/12"
    const jalurDari = (ref) => ref.replace(':', '/');

    const urlUntuk = (ref) => template.replace(PENAMPUNG, jalurDari(ref));

    const panel = document.querySelector('[data-file-panel]');
    if (!panel) return;

    const el = {
        title:   panel.querySelector('[data-file-title]'),
        meta:    panel.querySelector('[data-file-meta]'),
        url:     panel.querySelector('[data-file-url]'),
        error:   panel.querySelector('[data-file-error]'),
        preview: panel.querySelector('[data-file-preview]'),
        image:   panel.querySelector('[data-file-image]'),
        copy:    panel.querySelector('[data-file-copy]'),
        close:   panel.querySelector('[data-file-close]'),
        rename:  panel.querySelector('[data-file-form-rename]'),
        move:    panel.querySelector('[data-file-form-move]'),
        inputName: panel.querySelector('[data-file-input-name]'),
        inputDir:  panel.querySelector('[data-file-input-dir]'),
    };

    let urlTerakhir = null;

    const bersihkan = () => {
        el.error.hidden = true;
        el.url.hidden = true;
        el.copy.hidden = true;
        el.preview.hidden = true;
        el.image.removeAttribute('src');
        el.rename.hidden = true;
        el.move.hidden = true;
        urlTerakhir = null;
    };

    const buka = () => {
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    // --- Rincian dan pratayang ---
    table.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-file-detail]');
        if (!btn) return;

        bersihkan();
        el.title.textContent = 'Memuat rincian…';
        el.meta.textContent = '';
        buka();

        try {
            const res = await fetch(urlUntuk(btn.dataset.fileDetail), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });

            const json = await res.json();

            if (!res.ok || !json.ok) {
                throw new Error(json.message || `HTTP ${res.status}`);
            }

            const d = json.data;

            el.title.textContent = d.filename;

            el.meta.textContent =
                `${d.original} — ${d.size_human} — ${d.mime_type}`
                + (d.provider ? ` — ${d.provider}` : '')
                + ` — ${d.object_key}`;

            if (d.url) {
                urlTerakhir = d.url;
                el.url.hidden = false;
                el.url.textContent = d.url;
                el.copy.hidden = false;

                // Pratayang hanya untuk gambar, dan hanya bila ada URL yang
                // bisa dipasang. Berkas video sengaja tidak dipratayangkan:
                // menaruh <video> yang menunjuk berkas berukuran gigabyte akan
                // membuat peramban mulai mengunduhnya hanya karena panelnya
                // dibuka.
                if ((d.mime_type || '').startsWith('image/')) {
                    el.image.src = d.url;
                    el.preview.hidden = false;
                }
            } else if (d.url_note) {
                el.error.hidden = false;
                el.error.textContent = d.url_note;
            }
        } catch (err) {
            el.title.textContent = 'Rincian gagal dimuat';
            el.error.hidden = false;
            el.error.textContent = err.message;
        }
    });

    // --- Ganti nama ---
    table.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-file-rename]');
        if (!btn) return;

        bersihkan();
        el.title.textContent = `Ganti nama: ${btn.dataset.fileName || ''}`;
        el.meta.textContent = 'Ekstensinya tidak ikut berubah.';
        el.rename.action = el.rename.action.replace(PENAMPUNG, jalurDari(btn.dataset.fileRename));
        el.rename.hidden = false;
        el.inputName.value = btn.dataset.fileName || '';
        buka();
        el.inputName.focus();
    });

    // --- Pindahkan ---
    table.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-file-move]');
        if (!btn) return;

        bersihkan();
        el.title.textContent = 'Pindahkan berkas';
        el.meta.textContent = `Sekarang di: ${btn.dataset.fileDir || '(akar)'}`;
        el.move.action = el.move.action.replace(PENAMPUNG, jalurDari(btn.dataset.fileMove));
        el.move.hidden = false;
        el.inputDir.value = btn.dataset.fileDir || '';
        buka();
        el.inputDir.focus();
    });

    // --- Salin URL ---
    el.copy?.addEventListener('click', async () => {
        if (!urlTerakhir) return;

        const semula = el.copy.textContent;

        try {
            // `navigator.clipboard` hanya tersedia di konteks aman (https atau
            // localhost). Panel admin di server pengembangan yang diakses lewat
            // http tidak punya API ini sama sekali — tanpa cadangan di bawah,
            // tombolnya diam tanpa memberi tahu apa pun.
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(urlTerakhir);
            } else {
                const kotak = document.createElement('textarea');
                kotak.value = urlTerakhir;
                kotak.setAttribute('readonly', '');
                kotak.style.position = 'absolute';
                kotak.style.left = '-9999px';
                document.body.appendChild(kotak);
                kotak.select();
                document.execCommand('copy');
                kotak.remove();
            }

            el.copy.textContent = 'URL disalin';
        } catch {
            el.copy.textContent = 'Gagal menyalin — salin manual dari teks di atas';
        }

        setTimeout(() => { el.copy.textContent = semula; }, 2500);
    });

    el.close?.addEventListener('click', () => { panel.hidden = true; });
}

/*
|==============================================================================
| Sprint 7.9 — Batch Upload
|==============================================================================
*/

/**
 * Halaman Batch Upload.
 *
 * Tiap berkas dikirim sebagai permintaan tersendiri, BERURUTAN. Dua alasan,
 * keduanya praktis:
 *
 * - Progress per berkas hanya bisa didapat dari
 *   `XMLHttpRequest.upload.onprogress`, dan itu berlaku per permintaan. Satu
 *   permintaan berisi dua puluh berkas hanya punya satu angka.
 *
 * - Berurutan, bukan bersamaan. Dua puluh unggahan paralel akan berebut lebar
 *   pita yang sama sehingga semuanya lambat, dan sebagian server menutup
 *   koneksi yang terlalu banyak dari satu klien. Berurutan juga membuat
 *   "berhenti setelah yang ini" mungkin dilakukan.
 *
 * Kegagalan satu berkas TIDAK menghentikan sisanya: barisnya ditandai Gagal
 * beserta sebabnya, lalu perulangannya lanjut ke berkas berikutnya.
 */
function batchUpload() {
    const form = document.querySelector('[data-batch-upload]');
    if (!form) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;

    const PENAMPUNG_UUID = '00000000-0000-0000-0000-000000000000';

    const el = {
        kinds:     [...form.querySelectorAll('[data-kind]')],
        drama:     form.querySelector('[data-drama]'),
        dramaNote: form.querySelector('[data-drama-note]'),
        assetWrap: form.querySelector('[data-asset-wrap]'),
        assetType: form.querySelector('[data-asset-type]'),
        modes:     [...form.querySelectorAll('[data-mode]')],
        provider:  form.querySelector('[data-provider]'),
        providerWrap: form.querySelector('[data-provider-wrap]'),
        dropzone:  form.querySelector('[data-dropzone]'),
        input:     form.querySelector('[data-files]'),
        panel:     form.querySelector('[data-batch-panel]'),
        list:      form.querySelector('[data-batch-list]'),
        count:     form.querySelector('[data-batch-count]'),
        note:      form.querySelector('[data-batch-note]'),
        error:     form.querySelector('[data-batch-error]'),
        submit:    form.querySelector('[data-batch-submit]'),
        clear:     form.querySelector('[data-batch-clear]'),
        summary:   form.querySelector('[data-batch-summary]'),
    };

    /** @type {{file: File, li: HTMLElement, pilih: HTMLSelectElement|null}[]} */
    let antrean = [];

    let episodes = [];

    let batchUuid = null;

    const kind = () => el.kinds.find((r) => r.checked)?.value || 'video';

    const mode = () => el.modes.find((r) => r.checked)?.value || 'auto';

    const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(2)} MB`;

    const galat = (pesan) => {
        el.error.hidden = pesan === null;
        el.error.textContent = pesan || '';
    };

    /*
    |--------------------------------------------------------------------------
    | Pilihan tujuan
    |--------------------------------------------------------------------------
    */

    const perbaruiJenis = () => {
        const aset = kind() === 'asset';

        el.assetWrap.hidden = !aset;

        el.dramaNote.textContent = aset
            ? 'Seluruh berkas dalam batch ini menjadi aset drama yang dipilih.'
            : 'Daftar episodenya menyusul setelah drama dipilih.';

        // Batasan ekstensi di kotak berkas ikut berganti. Ini hanya
        // kenyamanan — penjagaan yang sebenarnya ada di FormRequest dan di
        // service, keduanya di server.
        if (aset) {
            const opsi = el.assetType.selectedOptions[0];
            el.input.accept = (opsi?.dataset.extensions || '')
                .split(',').filter(Boolean).map((e) => `.${e}`).join(',');
        } else {
            el.input.accept = '.mp4,.mkv,.webm,.mov,.m4v';
        }

        gambarUlang();
    };

    const perbaruiMode = () => {
        el.providerWrap.hidden = mode() !== 'manual';
    };

    /*
    |--------------------------------------------------------------------------
    | Episode
    |--------------------------------------------------------------------------
    */

    const muatEpisode = async () => {
        episodes = [];

        const id = el.drama.value;

        if (!id || kind() === 'asset') {
            gambarUlang();
            return;
        }

        try {
            const url = form.dataset.episodesUrl.replace(/\/0(\?|$)/, `/${id}$1`);

            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            episodes = (await res.json()).data || [];

            if (!episodes.length) {
                galat('Drama itu belum punya episode. Buat episodenya dulu lewat menu Episode.');
            } else {
                galat(null);
            }
        } catch (err) {
            galat(
                'Daftar episode gagal dimuat: ' + err.message + '. '
                + 'Kalau ini 403, peran Anda perlu izin episode.manage — daftar '
                + 'episode dibaca dari endpoint milik modul unggah video.'
            );
        }

        gambarUlang();
    };

    /**
     * Tebak nomor episode dari nama berkas.
     *
     * Yang diambil adalah rangkaian angka TERAKHIR sebelum ekstensi. Nama
     * berkas nyata biasanya berbentuk "Judul Drama S1 E07.mp4" atau
     * "drama-07.mkv", dan angka terakhirlah yang menyebut episodenya —
     * angka pertama justru sering nomor musim atau tahun.
     *
     * Tebakan ini SELALU bisa dikoreksi di dropdown. Itu bukan basa-basi:
     * tebakan yang salah akan mengganti video episode yang keliru, dan yang
     * lama sudah terlanjur dihapus ketika ketahuan.
     */
    const tebakNomor = (nama) => {
        const tanpaExt = nama.replace(/\.[^.]+$/, '');

        const angka = tanpaExt.match(/\d+/g);

        return angka ? parseInt(angka[angka.length - 1], 10) : null;
    };

    /*
    |--------------------------------------------------------------------------
    | Daftar berkas
    |--------------------------------------------------------------------------
    */

    const tambahBerkas = (fileList) => {
        const aset = kind() === 'asset';

        const banyakBoleh = !aset || el.assetType.selectedOptions[0]?.dataset.multiple === '1';

        for (const file of fileList) {
            // Berkas yang sama dipilih dua kali adalah kekeliruan yang wajar
            // saat menyeret dua kali. Ditolak diam-diam akan membingungkan,
            // jadi yang dilakukan hanya tidak menambahkannya lagi.
            if (antrean.some((i) => i.file.name === file.name && i.file.size === file.size)) {
                continue;
            }

            antrean.push({ file, li: null, pilih: null });
        }

        if (!banyakBoleh && antrean.length > 1) {
            galat(
                'Jenis aset ini hanya menerima satu berkas per drama. Berkas '
                + 'berikutnya akan menimpa yang sebelumnya, jadi hanya yang '
                + 'pertama yang dipertahankan.'
            );

            antrean = antrean.slice(0, 1);
        }

        gambarUlang();
    };

    const gambarUlang = () => {
        el.list.innerHTML = '';

        el.panel.hidden = antrean.length === 0;

        el.count.textContent = `${antrean.length} berkas`;

        const aset = kind() === 'asset';

        el.note.textContent = aset
            ? 'Seluruh berkas dikirim sebagai jenis aset yang dipilih di atas.'
            : 'Nomor episode ditebak dari nama berkas — periksa dan perbaiki sebelum '
              + 'mengunggah. Tebakan yang salah akan mengganti video episode yang keliru.';

        antrean.forEach((item, index) => {
            const li = document.createElement('li');
            li.className = 'batch-file';

            const utama = document.createElement('div');
            utama.className = 'batch-file-main';

            const nama = document.createElement('span');
            nama.className = 'batch-file-name';
            nama.textContent = item.file.name;

            const meta = document.createElement('span');
            meta.className = 'batch-file-meta';
            meta.textContent = `${mb(item.file.size)} — ${item.file.type || 'jenis tidak dikenali'}`;

            utama.appendChild(nama);
            utama.appendChild(meta);

            const tujuan = document.createElement('div');
            tujuan.className = 'batch-file-target';

            if (aset) {
                tujuan.textContent = el.assetType.selectedOptions[0]?.textContent?.split(' — ')[0] || 'Aset';
                item.pilih = null;
            } else {
                const pilih = document.createElement('select');
                pilih.className = 'control control-sm';

                const kosong = document.createElement('option');
                kosong.value = '';
                kosong.textContent = episodes.length ? '— pilih episode —' : '— pilih drama dulu —';
                pilih.appendChild(kosong);

                const tebakan = tebakNomor(item.file.name);

                episodes.forEach((ep) => {
                    const opt = document.createElement('option');
                    opt.value = ep.id;
                    opt.textContent = ep.label + (ep.has_video ? ' (sudah ada video)' : '');
                    if (tebakan !== null && Number(ep.number) === tebakan) {
                        opt.selected = true;
                    }
                    pilih.appendChild(opt);
                });

                tujuan.appendChild(pilih);
                item.pilih = pilih;
            }

            const progress = document.createElement('div');
            progress.className = 'progress';

            const track = document.createElement('div');
            track.className = 'progress-track';

            const bar = document.createElement('div');
            bar.className = 'progress-bar';
            bar.style.width = '0%';

            track.appendChild(bar);

            const label = document.createElement('span');
            label.className = 'progress-label';
            label.textContent = 'Menunggu';

            progress.appendChild(track);
            progress.appendChild(label);

            // Tombolnya berlabel kata, bukan karakter simbol.
            //
            // Aturan ikon proyek melarang simbol teks karena perenderannya
            // berbeda antar sistem operasi, dan komponen <x-web.home.icon>
            // tidak bisa dipanggil dari JavaScript. Menyalin SVG-nya ke sini
            // akan menjadi salinan kedua yang tertinggal saat yang asli
            // berubah — jadi yang dipakai adalah kata biasa.
            const buang = document.createElement('button');
            buang.type = 'button';
            buang.className = 'btn btn-ghost btn-sm';
            buang.title = 'Keluarkan dari daftar';
            buang.textContent = 'Buang';
            buang.addEventListener('click', () => {
                antrean.splice(index, 1);
                gambarUlang();
            });

            li.appendChild(utama);
            li.appendChild(tujuan);
            li.appendChild(progress);
            li.appendChild(buang);

            el.list.appendChild(li);

            item.li = li;
            item.bar = bar;
            item.label = label;
            item.buang = buang;
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Pengiriman
    |--------------------------------------------------------------------------
    */

    const kirimSatu = (item) => new Promise((resolve) => {
        const data = new FormData();

        data.append('_token', token);
        data.append('kind', kind());
        data.append('storage_mode', mode());
        data.append('file', item.file);

        if (batchUuid) data.append('batch', batchUuid);

        if (mode() === 'manual') {
            data.append('storage_provider_id', el.provider.value);
        }

        if (kind() === 'asset') {
            data.append('drama_id', el.drama.value);
            data.append('asset_type', el.assetType.value);
        } else {
            data.append('episode_id', item.pilih?.value || '');
        }

        const xhr = new XMLHttpRequest();

        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = (e) => {
            if (!e.lengthComputable) return;

            const persen = Math.round((e.loaded / e.total) * 100);

            item.bar.style.width = `${persen}%`;
            item.label.textContent = `Mengirim ${persen}%`;
        };

        xhr.onload = () => {
            let json = {};

            try {
                json = JSON.parse(xhr.responseText || '{}');
            } catch {
                json = {};
            }

            if (xhr.status === 202 && json.ok) {
                batchUuid = json.batch || batchUuid;

                item.uuid = json.data?.uuid || null;
                item.bar.style.width = '100%';
                item.label.textContent = 'Masuk antrean';
                item.li.classList.add('is-queued');

                resolve(true);
                return;
            }

            // 422 dari validasi Laravel membawa `errors`, bukan `message` kita.
            // Pesan pertama dari sana jauh lebih berguna daripada "berkas
            // ditolak" yang tidak menyebutkan apa pun.
            const pesan = json.message
                || Object.values(json.errors || {})[0]?.[0]
                || `Ditolak server (HTTP ${xhr.status})`;

            item.bar.style.width = '0%';
            item.label.textContent = pesan;
            item.li.classList.add('is-failed');

            resolve(false);
        };

        xhr.onerror = () => {
            item.label.textContent = 'Koneksi terputus sebelum berkas terkirim.';
            item.li.classList.add('is-failed');
            resolve(false);
        };

        xhr.send(data);
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!antrean.length) return;

        if (!el.drama.value) {
            galat('Pilih drama tujuan lebih dulu.');
            return;
        }

        if (mode() === 'manual' && !el.provider.value) {
            galat('Mode Manual memerlukan storage provider yang dipilih.');
            return;
        }

        if (kind() === 'video' && antrean.some((i) => !i.pilih?.value)) {
            galat('Masih ada berkas yang belum dipetakan ke episode.');
            return;
        }

        galat(null);

        el.submit.disabled = true;
        el.clear.disabled = true;
        el.input.disabled = true;
        antrean.forEach((i) => { i.buang.disabled = true; if (i.pilih) i.pilih.disabled = true; });

        let berhasil = 0;
        let gagal = 0;

        for (const item of antrean) {
            // Kegagalan satu berkas TIDAK menghentikan yang lain — inilah
            // tempat janji itu ditepati di sisi peramban.
            const ok = await kirimSatu(item);

            ok ? berhasil++ : gagal++;
        }

        el.summary.hidden = false;
        el.summary.textContent =
            `${berhasil} berkas masuk antrean, ${gagal} ditolak. `
            + 'Masuk antrean belum berarti tersimpan — pengirimannya ke storage '
            + 'provider berjalan di latar belakang.';

        const tautan = document.createElement('a');
        tautan.href = form.dataset.queueUrl;
        tautan.className = 'btn btn-ghost btn-sm';
        tautan.textContent = 'Lihat Upload Queue';
        el.summary.appendChild(tautan);

        el.clear.disabled = false;
        el.input.disabled = false;

        if (batchUuid) pantauBatch();
    });

    /**
     * Pantau nasib seluruh pekerjaan batch sampai semuanya final.
     *
     * Satu permintaan untuk seluruh batch, bukan satu per pekerjaan. Berhenti
     * sendiri ketika tidak ada lagi yang berubah — polling yang tidak pernah
     * berhenti akan terus berjalan selama tab dibiarkan terbuka.
     */
    const pantauBatch = () => {
        const url = (form.dataset.statusUrl || '').replace(PENAMPUNG_UUID, batchUuid);

        if (!url) return;

        const timer = setInterval(async () => {
            try {
                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const json = await res.json();

                (json.data || []).forEach((row) => {
                    const item = antrean.find((i) => i.uuid === row.uuid);
                    if (!item) return;

                    item.label.textContent = row.error
                        ? `${row.status_text}: ${row.error}`
                        : row.status_text;

                    item.li.classList.toggle('is-failed', row.status === 'failed');
                    item.li.classList.toggle('is-done', row.status === 'success');
                });

                if (json.done) {
                    clearInterval(timer);

                    el.summary.textContent =
                        `Selesai: ${json.ringkasan.sukses} berhasil, `
                        + `${json.ringkasan.gagal} gagal dari ${json.ringkasan.total} berkas.`;
                }
            } catch {
                // Satu kali gagal menanyakan tidak menghentikan pemantauan.
                // Percobaan berikutnya akan menanyakannya lagi.
            }
        }, 4000);
    };

    /*
    |--------------------------------------------------------------------------
    | Peristiwa
    |--------------------------------------------------------------------------
    */

    el.kinds.forEach((r) => r.addEventListener('change', () => { perbaruiJenis(); muatEpisode(); }));
    el.modes.forEach((r) => r.addEventListener('change', perbaruiMode));
    el.assetType?.addEventListener('change', perbaruiJenis);
    el.drama?.addEventListener('change', muatEpisode);

    el.input?.addEventListener('change', () => {
        tambahBerkas(el.input.files);

        // Kotak dikosongkan supaya berkas yang sama bisa dipilih lagi setelah
        // dikeluarkan dari daftar. Tanpa ini, event `change` tidak menyala
        // untuk pilihan yang identik dengan sebelumnya.
        el.input.value = '';
    });

    el.clear?.addEventListener('click', () => {
        antrean = [];
        batchUuid = null;
        el.summary.hidden = true;
        el.submit.disabled = false;
        galat(null);
        gambarUlang();
    });

    ['dragenter', 'dragover'].forEach((ev) =>
        el.dropzone?.addEventListener(ev, (e) => {
            e.preventDefault();
            el.dropzone.classList.add('is-dragging');
        })
    );

    ['dragleave', 'drop'].forEach((ev) =>
        el.dropzone?.addEventListener(ev, (e) => {
            e.preventDefault();
            el.dropzone.classList.remove('is-dragging');
        })
    );

    el.dropzone?.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.length) tambahBerkas(e.dataTransfer.files);
    });

    perbaruiJenis();
    perbaruiMode();
}