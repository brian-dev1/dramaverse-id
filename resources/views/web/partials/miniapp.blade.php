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
        font: 600 14px/1.4 'Work Sans', system-ui, sans-serif;
        letter-spacing: .02em;
    }
    body.tg-authenticating #tg-boot { display: flex; }
</style>

<div id="tg-boot" aria-hidden="true">Menghubungkan akun Telegram…</div>

<script>
(function () {
    var tg = window.Telegram && window.Telegram.WebApp;

    if (!tg || !tg.initData) {
        return; // Dibuka lewat browser biasa — tidak ada yang perlu dilakukan.
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
        })
        .catch(function (err) {
            console.warn('Mini App login error:', err);
            body.classList.remove('tg-authenticating');
        });
    })();
    @endguest
})();
</script>
