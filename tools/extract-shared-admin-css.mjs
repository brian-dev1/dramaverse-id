/*
|------------------------------------------------------------------------------
| Pemisah CSS admin yang "bocor" ke sisi web
|------------------------------------------------------------------------------
| Latar belakang
| --------------
| CSS panel admin (~66 KB) dulu ikut dimuat di setiap halaman mini app, padahal
| panel admin hanya dibuka di desktop. Masalahnya tidak sesederhana "buang saja":
| sebagian aturan di berkas admin TIDAK berscope `.admin-body`, dan halaman
| publik sudah terlanjur bergantung padanya. Contoh paling nyata: warna
| `.btn-primary` di seluruh situs berasal dari admin.css baris ~1072 (emas),
| bukan dari theme.css. Halaman invoice, profil, dan membership juga memakai
| `.panel`, `.data-table`, dan `.badge` yang definisinya hanya ada di admin.css.
|
| Cara kerja
| ----------
| Skrip ini membaca berkas CSS admin lalu memisahkan tiap aturan ke dua nasib:
|
|   DIBUANG  bila selectornya mustahil cocok di halaman publik, yaitu bila ia
|            berscope admin (`.admin-body`, `.admin-*`, `[data-shell]`) ATAU
|            mensyaratkan kelas yang tidak pernah muncul di satu pun blade
|            non-admin.
|   DISIMPAN bila selectornya masih mungkin cocok — termasuk selector elemen
|            murni seperti `table th` yang tidak menyebut kelas apa pun.
|
| Aturan yang disimpan ditulis ke resources/css/web/shared/ dengan URUTAN ASLI
| dipertahankan, dipecah tiga berkas sesuai posisi impornya dulu di app.css
| (sebelum vintage.css, sesudahnya, lalu paling akhir). Karena urutan dan isi
| yang mungkin cocok dipertahankan utuh, hasil render halaman publik identik.
|
| Panel admin sendiri tidak disentuh: resources/css/admin.css tetap mengimpor
| berkas admin yang asli, lengkap.
|
| Skrip ini jalan otomatis lewat `npm run build` (script "prebuild"), jadi hasil
| ekstraksi tidak bisa basi ketika berkas admin diedit. Bisa juga dipanggil
| manual: node tools/extract-shared-admin-css.mjs
*/

import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

/* Kelompok berkas = posisi impornya dulu di app.css. Urutan di dalam array
   juga urutan impor aslinya, jadi jangan diacak. */
const GROUPS = [
    {
        out: 'resources/css/web/shared/admin-shared-1.css',
        posisi: 'sebelum vintage.css',
        sumber: [
            'resources/css/web/admin/admin.css',
            'resources/css/web/admin/panel-spacing.css',
            'resources/css/web/admin/action-layout.css',
        ],
    },
    {
        out: 'resources/css/web/shared/admin-shared-2.css',
        posisi: 'sesudah vintage.css',
        sumber: [
            'resources/css/web/admin/admin-refine.css',
            'resources/css/web/admin/referral-admin.css',
        ],
    },
    {
        out: 'resources/css/web/shared/admin-shared-3.css',
        posisi: 'sesudah detail/responsive.css',
        sumber: [
            'resources/css/web/admin/toolbar-fix.css',
        ],
    },
];

const ADMIN_TOKEN = /admin-body|\.admin-|\[data-shell\]|#admin/;

/* Kelas yang tidak pernah tertulis di blade karena ditambahkan JS sisi web.
   Diperlakukan seolah ada di markup supaya aturannya tidak ikut terbuang. */
const KELAS_DARI_JS = ['scrolled', 'is-visible', 'active'];

/* ---------------------------------------------------------------- markup ---- */

function kumpulkanBlade(dir, hasil = []) {
    for (const nama of readdirSync(dir)) {
        const p = join(dir, nama);
        if (statSync(p).isDirectory()) kumpulkanBlade(p, hasil);
        else if (nama.endsWith('.blade.php')) hasil.push(p);
    }
    return hasil;
}

function kelasHalamanPublik() {
    const set = new Set(KELAS_DARI_JS);

    for (const berkas of kumpulkanBlade(join(ROOT, 'resources/views'))) {
        // Blade panel admin sengaja dilewati: kelas yang HANYA muncul di sana
        // memang tidak perlu ada di bundle publik.
        if (relative(ROOT, berkas).includes('admin')) continue;

        const isi = readFileSync(berkas, 'utf8');

        for (const m of isi.matchAll(/class\s*=\s*["']([^"']*)["']/g)) {
            for (const kelas of m[1].split(/[^\w-]+/)) {
                if (kelas) set.add(kelas);
            }
        }
    }
    return set;
}

/* ------------------------------------------------------------------- css ---- */

/**
 * Membuang komentar sebelum apa pun diurai.
 *
 * Wajib dilakukan lebih dulu karena dua alasan: komentar bisa memuat kurung
 * kurawal yang mengacaukan penghitungan blok, dan teks komentar yang kebetulan
 * memuat kata berpola admin bisa membuat aturan di bawahnya ikut terbuang
 * padahal selectornya sendiri bersih.
 */
function buangKomentar(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Memecah CSS menjadi daftar { selector, isi, mentah } pada satu tingkat. */
function pecah(css) {
    const hasil = [];
    let i = 0;

    while (i < css.length) {
        const buka = css.indexOf('{', i);
        if (buka < 0) break;

        const selector = css.slice(i, buka).trim();
        let dalam = 1;
        let k = buka + 1;

        while (k < css.length && dalam > 0) {
            if (css[k] === '{') dalam++;
            else if (css[k] === '}') dalam--;
            k++;
        }

        hasil.push({ selector, isi: css.slice(buka + 1, k - 1), mentah: css.slice(i, k) });
        i = k;
    }
    return hasil;
}

/**
 * Mengembalikan cabang-cabang selector (yang dipisah koma) yang masih mungkin
 * cocok dengan markup halaman publik.
 *
 * Penyaringan WAJIB per cabang, bukan atas seluruh selector sekaligus. Satu
 * aturan sering menggabungkan sasaran admin dan sasaran umum, misalnya:
 *
 *     .panel-head,
 *     .panel > .admin-form,
 *     .panel > .detail-body-admin { padding: 16px }
 *
 * Menilai string utuhnya akan membuang aturan itu gara-gara `.admin-form`,
 * padahal `.panel-head` dipakai halaman invoice publik dan jarak paddingnya
 * ikut hilang.
 */
function cabangYangDipakai(selector, kelas) {
    const hidup = [];

    for (const cabang of selector.split(',').map((s) => s.trim()).filter(Boolean)) {
        if (ADMIN_TOKEN.test(cabang)) continue;

        const diminta = [...cabang.matchAll(/\.([A-Za-z_][\w-]*)/g)].map((m) => m[1]);

        // Tidak menyebut kelas sama sekali (mis. `table th`) — selalu mungkin.
        if (diminta.length === 0 || diminta.every((c) => kelas.has(c))) {
            hidup.push(cabang);
        }
    }
    return hidup;
}

function saring(css, kelas) {
    let keluar = '';

    for (const aturan of pecah(css)) {
        if (aturan.selector.startsWith('@')) {
            // @media / @supports: saring isinya, buang bila jadi kosong.
            const dalam = saring(aturan.isi, kelas);
            if (dalam.trim()) keluar += `${aturan.selector} {\n${dalam}}\n`;
            continue;
        }

        const hidup = cabangYangDipakai(aturan.selector, kelas);

        if (hidup.length === 0) continue;

        // Semua cabang lolos: salin apa adanya supaya format aslinya terjaga.
        // Sebagian saja: tulis ulang tanpa cabang yang khusus admin.
        keluar += hidup.length === aturan.selector.split(',').filter((s) => s.trim()).length
            ? `${aturan.mentah.trim()}\n`
            : `${hidup.join(',\n')} {${aturan.isi.trimEnd()}\n}\n`;
    }
    return keluar;
}

/* ------------------------------------------------------------------ jalan --- */

const kelas = kelasHalamanPublik();
let totalSimpan = 0;
let totalBuang = 0;

for (const grup of GROUPS) {
    let badan = '';

    for (const sumber of grup.sumber) {
        const css = buangKomentar(readFileSync(join(ROOT, sumber), 'utf8'));
        const hasil = saring(css, kelas);

        totalSimpan += hasil.length;
        totalBuang += css.length - hasil.length;

        if (hasil.trim()) {
            badan += `\n/* ===== ${sumber} ===== */\n${hasil}`;
        }
    }

    const kepala = `/*
 * DIHASILKAN OTOMATIS — JANGAN DIEDIT TANGAN.
 * Sumber : ${grup.sumber.join(', ')}
 * Pembuat: tools/extract-shared-admin-css.mjs (jalan lewat "npm run build")
 * Posisi : diimpor app.css ${grup.posisi}, sama seperti berkas aslinya dulu.
 *
 * Isinya hanya aturan CSS admin yang masih mungkin cocok dengan markup halaman
 * publik. Sisanya tetap tinggal di berkas admin dan hanya dimuat lewat
 * resources/css/admin.css. Ubah gayanya di berkas sumber, bukan di sini.
 */
`;

    const tujuan = join(ROOT, grup.out);
    mkdirSync(dirname(tujuan), { recursive: true });
    writeFileSync(tujuan, kepala + badan, 'utf8');

    console.log(`  ${grup.out}  ${(kepala + badan).length.toLocaleString()} B`);
}

console.log(
    `CSS admin: ${totalSimpan.toLocaleString()} B tetap di bundle publik, ` +
    `${totalBuang.toLocaleString()} B pindah ke bundle admin saja.`
);
