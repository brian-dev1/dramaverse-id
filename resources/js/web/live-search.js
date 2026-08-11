/*
| Pencarian langsung
| ------------------
| Hasil muncul saat mengetik, tanpa menekan Enter. Dipasang di dua tempat:
|
|   1. Kolom pencarian di navbar — hasilnya panel melayang di bawah kolom.
|   2. Halaman /search — hasilnya menggantikan grid di badan halaman.
|
| Keduanya berbagi satu pengambil data supaya aturan "mana yang dianggap
| kosong", "berapa lama menunggu", dan "filter apa yang ikut" tidak ditulis
| dua kali dengan dua jawaban berbeda.
|
| Dibangun sebagai LAPISAN di atas form yang sudah bisa dikirim, bukan
| penggantinya. Kalau skrip ini gagal dimuat, Enter tetap membawa ke halaman
| hasil seperti biasa. Yang hilang cuma kenyamanannya.
|
| Dua hal yang mudah salah saat menyuntingnya:
|
| - Ketikan ditunda. Tanpa itu, mengetik "cinta" mengirim lima permintaan
|   untuk satu niat.
|
| - Jawaban yang datang terlambat DIBUANG. Jaringan tidak menjamin urutan:
|   jawaban untuk "cin" bisa tiba setelah jawaban untuk "cinta", dan tanpa
|   penomoran layar akan menampilkan hasil yang salah untuk kata yang benar.
*/

const JEDA_KETIK = 280;

const PANJANG_MINIMAL = 2;

/* ------------------------------------------------------------------ */
/* Pengambil data                                                     */
/* ------------------------------------------------------------------ */

function buatPencari(form, endpoint) {
    let nomor = 0;

    return (kata, onHasil, onGalat) => {
        const ini = ++nomor;

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('q', kata);

        // Filter yang sedang dipilih ikut terbawa, supaya hasil langsung dan
        // hasil setelah Enter tidak menunjukkan dua kumpulan berbeda.
        ['genre', 'country', 'status', 'sort'].forEach((nama) => {
            const el = form.querySelector('[name="' + nama + '"]');
            if (el && el.value) url.searchParams.set(nama, el.value);
        });

        const vip = form.querySelector('[name="vip"]');
        if (vip && vip.checked) url.searchParams.set('vip', '1');

        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(r.status))))
            .then((data) => {
                if (ini !== nomor) return;
                onHasil(data);
            })
            .catch(() => {
                if (ini !== nomor) return;
                onGalat();
            });

        return () => ini === nomor;
    };
}

/** Menunda pemanggilan sampai orangnya berhenti mengetik sejenak. */
function tunda(fn) {
    let timer = null;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), JEDA_KETIK);
    };
}

function urlRequest(dasar, kata) {
    return dasar ? dasar + '?q=' + encodeURIComponent(kata) : '#';
}

/* ------------------------------------------------------------------ */
/* Kartu drama untuk halaman /search                                  */
/* ------------------------------------------------------------------ */

/*
| Judul dimasukkan lewat textContent, bukan innerHTML. Judul drama datang
| dari basis data, dan suatu hari akan ada judul yang memuat tanda kurung
| siku atau kutip.
*/
function kartu(item) {
    const a = document.createElement('a');
    a.className = 'dv-card';
    a.href = item.url;
    a.setAttribute('aria-label', item.title);

    const poster = document.createElement('div');
    poster.className = 'dv-poster ' + (item.gradient || 'g1');

    if (item.poster) {
        const img = document.createElement('img');
        img.src = item.poster;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.width = 300;
        img.height = 450;
        poster.appendChild(img);
    }

    const shade = document.createElement('span');
    shade.className = 'dv-shade';
    shade.setAttribute('aria-hidden', 'true');
    poster.appendChild(shade);

    if (item.vip) {
        const tags = document.createElement('div');
        tags.className = 'dv-tags';
        const vip = document.createElement('span');
        vip.className = 'dv-tag dv-tag-vip';
        vip.textContent = 'VIP';
        tags.appendChild(vip);
        poster.appendChild(tags);
    }

    const meta = document.createElement('div');
    meta.className = 'dv-meta';

    const judul = document.createElement('span');
    judul.className = 'dv-title';
    judul.textContent = item.title;

    const sub = document.createElement('span');
    sub.className = 'dv-sub';
    sub.textContent = [item.country, item.episodes ? item.episodes + ' EP' : null]
        .filter(Boolean)
        .join(' · ');

    meta.append(judul, sub);
    a.append(poster, meta);

    return a;
}

/* ------------------------------------------------------------------ */
/* 1. Navbar — panel melayang                                         */
/* ------------------------------------------------------------------ */

function navbarSearch() {
    const form = document.querySelector('[data-navsearch]');

    if (!form) return;

    const input = form.querySelector('[data-navsearch-input]');
    const panel = form.querySelector('[data-navsearch-panel]');

    if (!input || !panel) return;

    const cari = buatPencari(form, form.dataset.endpoint);
    const reqUrl = form.dataset.requestUrl;

    const tutup = () => {
        panel.hidden = true;
        panel.innerHTML = '';
    };

    const pesan = (teks, tautan) => {
        panel.innerHTML = '';

        const p = document.createElement('p');
        p.className = 'nav-results-msg';
        p.textContent = teks;
        panel.appendChild(p);

        if (tautan) panel.appendChild(tautan);

        panel.hidden = false;
    };

    const baris = (item) => {
        const a = document.createElement('a');
        a.className = 'nav-result';
        a.href = item.url;

        if (item.poster) {
            const img = document.createElement('img');
            img.src = item.poster;
            img.alt = '';
            img.loading = 'lazy';
            a.appendChild(img);
        }

        const teks = document.createElement('span');

        const judul = document.createElement('strong');
        judul.textContent = item.title;

        const sub = document.createElement('small');
        sub.textContent = [item.country, item.episodes ? item.episodes + ' EP' : null]
            .filter(Boolean)
            .join(' · ');

        teks.append(judul, sub);
        a.appendChild(teks);

        if (item.vip) {
            const vip = document.createElement('span');
            vip.className = 'nav-result-vip';
            vip.textContent = 'VIP';
            a.appendChild(vip);
        }

        return a;
    };

    const render = (data) => {
        if (!data.items.length) {
            const tautan = document.createElement('a');
            tautan.className = 'nav-results-cta';
            tautan.href = urlRequest(reqUrl, data.query);
            tautan.textContent = 'Request drama ini';

            pesan('Drama yang dicari tidak tersedia. Silakan request bila perlu.', tautan);

            return;
        }

        panel.innerHTML = '';

        // Panel navbar sengaja dibatasi delapan baris. Ini pintasan untuk
        // melihat sekilas, bukan halaman hasil — daftar yang lebih panjang
        // dari layar membuat orang menggulir di dalam kotak melayang, hal
        // yang di ponsel hampir selalu salah sasaran.
        data.items.slice(0, 8).forEach((i) => panel.appendChild(baris(i)));

        if (data.total > 8) {
            const semua = document.createElement('a');
            semua.className = 'nav-results-cta';
            semua.href = form.action + '?q=' + encodeURIComponent(data.query);
            semua.textContent = 'Lihat semua ' + data.total + ' hasil';
            panel.appendChild(semua);
        }

        panel.hidden = false;
    };

    const jalan = tunda((kata) => {
        cari(kata, render, () => tutup());
    });

    input.addEventListener('input', () => {
        const kata = input.value.trim();

        if (kata.length < PANJANG_MINIMAL) {
            tutup();
            return;
        }

        pesan('Mencari…');
        jalan(kata);
    });

    // Klik di luar menutup panel. Tanpa ini, panelnya menutupi isi halaman
    // sampai orangnya menghapus ketikannya sendiri.
    document.addEventListener('click', (e) => {
        if (!form.contains(e.target)) tutup();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') tutup();
    });
}

/* ------------------------------------------------------------------ */
/* 2. Halaman /search — hasil di badan halaman                        */
/* ------------------------------------------------------------------ */

function pageSearch() {
    const form = document.querySelector('[data-live-search]');

    if (!form) return;

    const input = form.querySelector('[data-live-input]');
    const wrap = document.querySelector('[data-live-wrap]');
    const hasilEl = document.querySelector('[data-live-results]');
    const serverEl = document.querySelector('[data-server-results]');

    if (!input || !wrap || !hasilEl) return;

    const memuat = wrap.querySelector('[data-live-loading]');
    const kosong = wrap.querySelector('[data-live-empty]');
    const galat = wrap.querySelector('[data-live-error]');
    const kataEl = wrap.querySelector('[data-live-keyword]');
    const tombolReq = wrap.querySelector('[data-live-request]');

    const cari = buatPencari(form, form.dataset.endpoint);
    const reqUrl = form.dataset.requestUrl;

    const tampilkan = (el) => {
        [memuat, kosong, galat].forEach((s) => s && s.classList.toggle('is-on', s === el));
    };

    const bersihkan = () => {
        wrap.hidden = true;
        hasilEl.innerHTML = '';
        tampilkan(null);
        if (serverEl) serverEl.hidden = false;
    };

    const render = (data) => {
        hasilEl.innerHTML = '';

        if (!data.items.length) {
            if (kataEl) kataEl.textContent = '"' + data.query + '"';
            if (tombolReq) tombolReq.href = urlRequest(reqUrl, data.query);

            tampilkan(kosong);
            return;
        }

        tampilkan(null);

        const judul = document.createElement('p');
        judul.className = 'live-hint';
        judul.textContent = data.total + ' drama ditemukan';

        const grid = document.createElement('div');
        grid.className = 'grid';
        data.items.forEach((i) => grid.appendChild(kartu(i)));

        hasilEl.append(judul, grid);
    };

    const jalan = tunda((kata) => {
        cari(kata, render, () => {
            hasilEl.innerHTML = '';
            tampilkan(galat);
        });
    });

    input.addEventListener('input', () => {
        const kata = input.value.trim();

        if (kata.length < PANJANG_MINIMAL) {
            // Satu huruf cocok dengan hampir seluruh katalog: hasilnya tidak
            // membantu siapa pun, dan query-nya yang paling mahal.
            bersihkan();
            return;
        }

        wrap.hidden = false;
        if (serverEl) serverEl.hidden = true;
        tampilkan(memuat);

        jalan(kata);
    });

    // Halaman yang dibuka dengan kata kunci di URL TIDAK dicari ulang di
    // sini: hasilnya sudah dirender server, lengkap dengan pagination.
    // Menjalankannya lagi hanya menghasilkan satu permintaan tambahan untuk
    // menampilkan hal yang sama, tanpa halaman berikutnya.
}

export default function liveSearch() {
    navbarSearch();
    pageSearch();
}
