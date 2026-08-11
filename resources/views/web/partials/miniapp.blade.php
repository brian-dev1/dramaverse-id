{{--
    Lapisan Telegram Mini App.

    Aktif hanya ketika halaman dibuka di dalam Telegram. Tugasnya:
    1. Menyesuaikan tampilan (expand, warna, safe area, tombol kembali).
    2. Menukar initData menjadi sesi login tanpa perlu klik apa pun.
--}}
<script src="https://telegram.org/js/telegram-web-app.js"></script>

<style>
    body.tg-miniapp {
        padding-top: var(--tg-safe-top, 0px);
        padding-bottom: var(--tg-safe-bottom, 0px);
    }
    #tg-boot {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        background: #140A06;
        color: #fff;
        font: 600 14px/1.5 'Work Sans', system-ui, sans-serif;
        letter-spacing: .02em;
        white-space: pre-wrap;
        word-break: break-word;
    }
    body.tg-authenticating #tg-boot { display: flex; }
</style>

<div id="tg-boot" aria-hidden="true">Menghubungkan akun Telegram…</div>

<script>
(function () {
    var tg = window.Telegram && window.Telegram.WebApp;

    // Mode diagnosa: buka halaman dengan ?tgdebug=1 untuk melihat kenapa
    // login otomatis tidak jalan, langsung di layar ponsel.
    var debug = window.location.search.indexOf('tgdebug=1') !== -1;

    function lapor(pesan) {
        if (!debug) return;
        var el = document.getElementById('tg-boot');
        el.textContent = pesan;
        el.style.display = 'flex';
        el.style.padding = '24px';
        el.style.textAlign = 'center';
        el.onclick = function () { el.style.display = 'none'; };
    }

    if (!tg) {
        lapor('SDK Telegram tidak ada. Halaman ini dibuka lewat browser biasa '
            + 'atau in-app browser Telegram, bukan sebagai Mini App.');
        return;
    }

    /*
    | Tautan t.me di dalam Mini App
    | -----------------------------
    | Di dalam Mini App, <a href="https://t.me/..." target="_blank"> TIDAK
    | melakukan apa-apa. Webview-nya bukan tab browser: Telegram memblokir
    | window.open ke domainnya sendiri, jadi tombol "Tonton di Telegram"
    | terlihat bisa diklik tapi diam saja — persis keluhan yang muncul.
    |
    | Satu-satunya jalan yang sah adalah openTelegramLink(), yang menutup
    | Mini App lalu membuka chat botnya. Dipasang sebagai delegasi di
    | document supaya SEMUA tautan t.me ikut tertangani — tombol tonton,
    | poster, dan tombol berlangganan — termasuk yang dirender belakangan.
    |
    | Didaftarkan SEBELUM pemeriksaan initData: halaman bisa saja dibuka
    | tanpa initData (mis. dari tautan biasa di dalam Telegram) dan
    | tombolnya tetap harus berfungsi.
    */
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;

        if (!a) return;

        /*
        | Pintasan "Tonton Sekarang"
        | --------------------------
        | Tombol tonton di situs menyimpan alamat halaman biasa di href, dan
        | tautan botnya di data-tg-href. Alasannya ada di
        | App\Support\TelegramDeepLink::attribute(): situs yang sama juga
        | dibuka dari browser desktop, dan di sana melempar orang keluar ke
        | Telegram begitu menekan tombol pertama adalah pengusiran, bukan
        | pintasan.
        |
        | Blok ini hanya berjalan di dalam Telegram — berkas ini sendiri
        | keluar lebih awal bila window.Telegram.WebApp tidak ada. Jadi
        | pemilihan tujuannya terjadi di sini, bukan saat halaman dirender,
        | dan satu HTML yang sama melayani kedua tempat.
        */
        var pintasan = a.getAttribute('data-tg-href');

        if (pintasan && tg.openTelegramLink) {
            e.preventDefault();

            try {
                tg.openTelegramLink(pintasan);
            } catch (err) {
                // Biarkan tombolnya tetap berguna: jatuh kembali ke halaman
                // episode di situs, bukan diam saja.
                window.location.href = a.href;
            }

            return;
        }

        var href = a.getAttribute('href') || '';

        if (!/^(https?:\/\/(t\.me|telegram\.me)\/|tg:\/\/)/i.test(href)) return;

        // Hanya dicegat kalau ada jalan penggantinya. Kalau SDK-nya versi
        // lama dan openTelegramLink tidak ada, biarkan perilaku bawaan —
        // lebih baik tautan biasa daripada klik yang kita telan sendiri.
        if (!tg.openTelegramLink) return;

        e.preventDefault();

        try {
            tg.openTelegramLink(a.href);
        } catch (err) {
            window.location.href = a.href;
        }
    }, false);

    if (!tg.initData) {
        lapor('Telegram.WebApp terdeteksi tetapi initData KOSONG.\n\n'
            + 'Artinya halaman dibuka bukan lewat tombol Mini App. '
            + 'platform=' + (tg.platform || '?') + ' versi=' + (tg.version || '?'));
        return;
    }

    var body = document.body;
    body.classList.add('tg-miniapp');

    try { tg.ready(); tg.expand(); } catch (e) {}
    try { tg.disableVerticalSwipes && tg.disableVerticalSwipes(); } catch (e) {}

    // Dikunci potret. requestFullscreen() sengaja TIDAK dipakai: di mode
    // itu Telegram membiarkan jendela ikut berputar mengikuti sensor, dan
    // tata letak mobile yang memang dirancang potret jadi melebar.
    try { tg.lockOrientation && tg.lockOrientation(); } catch (e) {}
    try { screen.orientation && screen.orientation.lock && screen.orientation.lock('portrait').catch(function () {}); } catch (e) {}

    // Warna header/latar mengikuti tema situs.
    try {
        tg.setHeaderColor && tg.setHeaderColor('#140A06');
        tg.setBackgroundColor && tg.setBackgroundColor('#140A06');
    } catch (e) {}

    function applyInsets() {
        var s = (tg.safeAreaInset || {});
        var c = (tg.contentSafeAreaInset || {});
        var top = (s.top || 0) + (c.top || 0);
        var bottom = (s.bottom || 0) + (c.bottom || 0);
        body.style.setProperty('--tg-safe-top', top + 'px');
        body.style.setProperty('--tg-safe-bottom', bottom + 'px');
    }
    applyInsets();
    try {
        tg.onEvent('safeAreaChanged', applyInsets);
        tg.onEvent('contentSafeAreaChanged', applyInsets);
    } catch (e) {}

    // Tombol kembali bawaan Telegram menggantikan tombol back browser.
    try {
        if (window.history.length > 1 && tg.BackButton) {
            tg.BackButton.show();
            tg.BackButton.onClick(function () { window.history.back(); });
        }
    } catch (e) {}

    @guest
    // Login otomatis: kirim initData yang sudah ditandatangani Telegram.
    (function login() {
        body.classList.add('tg-authenticating');

        var token = document.querySelector('meta[name="csrf-token"]');

        fetch(@json(route('web.telegram.miniapp')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token ? token.content : ''
            },
            body: JSON.stringify({ init_data: tg.initData })
        })
        .then(function (r) {
            return r.json().catch(function () { return { status: r.status }; });
        })
        .then(function (data) {
            if (data && data.ok) {
                // replace(), bukan reload(): reload mengulang POST kalau
                // halaman ini sendiri hasil kiriman form.
                window.location.replace(window.location.href);
                return;
            }
            console.warn('Mini App login gagal:', data);
            body.classList.remove('tg-authenticating');
            lapor('Login ditolak server.\n\n' + JSON.stringify(data));
        })
        .catch(function (err) {
            console.warn('Mini App login error:', err);
            body.classList.remove('tg-authenticating');
            lapor('Permintaan login gagal terkirim.\n\n' + err);
        });
    })();
    @endguest
})();
</script>
