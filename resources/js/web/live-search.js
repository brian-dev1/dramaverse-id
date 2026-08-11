/*
| Pencarian langsung
| ------------------
| Hasil muncul saat mengetik, tanpa menekan Enter.
|
| Dibangun sebagai LAPISAN di atas form yang sudah bisa dikirim, bukan
| penggantinya. Kalau JavaScript gagal dimuat — jaringan buruk, peramban lama,
| skrip diblokir — form-nya tetap form biasa dan Enter tetap membawa ke
| halaman hasil. Yang hilang cuma kenyamanannya, bukan fiturnya.
|
| Yang perlu diperhatikan saat menyuntingnya:
|
| 1. Ketikan di-tunda (debounce). Tanpa itu, mengetik "reply 1988" mengirim
|    sepuluh permintaan untuk satu niat, dan sembilan di antaranya sudah tidak
|    relevan sebelum jawabannya sampai.
|
| 2. Jawaban yang datang terlambat DIBUANG. Jaringan tidak menjamin urutan:
|    permintaan untuk "rep" bisa tiba setelah permintaan untuk "reply", dan
|    tanpa penomoran, layar akan menampilkan hasil "rep" untuk kata "reply".
|
| 3. Judul dari server dimasukkan lewat textContent, bukan innerHTML. Judul
|    drama berasal dari basis data, dan satu hari nanti akan ada judul yang
|    memuat tanda kutip atau kurung siku.
*/

const JEDA_KETIK = 300;

const PANJANG_MINIMAL = 2;

export default function liveSearch() {
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

    const endpoint = form.dataset.endpoint;
    const requestUrl = form.dataset.requestUrl;

    let timer = null;
    let nomor = 0;

    const tampilkan = (el) => {
        [memuat, kosong, galat].forEach((s) => s && s.classList.toggle('is-on', s === el));
    };

    // Kembali ke tampilan server: dipakai saat kolom dikosongkan lagi.
    const bersihkan = () => {
        wrap.hidden = true;
        hasilEl.innerHTML = '';
        tampilkan(null);
        if (serverEl) serverEl.hidden = false;
    };

    const kartu = (item) => {
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
    };

    const render = (data) => {
        hasilEl.innerHTML = '';

        if (!data.items.length) {
            if (kataEl) kataEl.textContent = '"' + data.query + '"';

            // Judulnya dibawa ke form request supaya kolomnya sudah terisi.
            if (tombolReq && requestUrl) {
                tombolReq.href = requestUrl + '?q=' + encodeURIComponent(data.query);
            }

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

    const cari = (kata) => {
        const ini = ++nomor;

        wrap.hidden = false;
        if (serverEl) serverEl.hidden = true;
        tampilkan(memuat);

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
                // Jawaban usang: sudah ada ketikan yang lebih baru.
                if (ini !== nomor) return;
                render(data);
            })
            .catch(() => {
                if (ini !== nomor) return;
                hasilEl.innerHTML = '';
                tampilkan(galat);
            });
    };

    input.addEventListener('input', () => {
        const kata = input.value.trim();

        clearTimeout(timer);

        if (kata.length < PANJANG_MINIMAL) {
            // Satu huruf cocok dengan hampir seluruh katalog. Hasilnya tidak
            // membantu siapa pun dan query-nya paling mahal.
            nomor++;
            bersihkan();
            return;
        }

        timer = setTimeout(() => cari(kata), JEDA_KETIK);
    });

    // Enter tetap berfungsi seperti biasa: membuka halaman hasil yang bisa
    // di-bookmark dan punya pagination. Pencarian langsung hanya untuk
    // melihat sekilas.
}
