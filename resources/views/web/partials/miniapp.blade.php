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
    | Satu-satunya jalan yang sah adalah openTelegramLink(). Dipasang sebagai
    | delegasi di document supaya SEMUA tautan t.me ikut tertangani — tombol
    | tonton, poster, dan tombol berlangganan — termasuk yang dirender
    | belakangan.
    |
    | Didaftarkan SEBELUM pemeriksaan initData: halaman bisa saja dibuka
    | tanpa initData (mis. dari tautan biasa di dalam Telegram) dan
    | tombolnya tetap harus berfungsi.
    */

    /*
    | Membuka chat bot, lalu menutup Mini App-nya
    | -------------------------------------------
    | `openTelegramLink()` dulu menutup Mini App dengan sendirinya. Sejak
    | Bot API 7.0 ia TIDAK lagi begitu — dokumentasinya sekarang menyebut
    | "the Mini App will not be closed after this method is called".
    |
    | Akibatnya persis keluhan yang muncul: menekan Tonton membuka chat bot
    | di belakang, sementara Mini App tetap terbentang di depannya. Pengguna
    | melihat halaman yang sama seolah tombolnya tidak bekerja, menekannya
    | lagi, dan bot menerima dua permintaan untuk satu niat.
    |
    | Jadi penutupannya dilakukan sendiri. Diberi jeda pendek, bukan
    | dipanggil langsung: `close()` yang menyusul di baris yang sama kadang
    | mendahului perpindahannya, dan yang terjadi hanyalah Mini App tertutup
    | tanpa chat bot pernah terbuka. Seperlima detik cukup bagi Telegram
    | memproses tautannya, dan tidak cukup lama untuk terasa sebagai jeda.
    */
    function bukaChatBot(url, cadangan) {
        try {
            tg.openTelegramLink(url);
        } catch (err) {
            // Tautannya ditolak — jatuh kembali ke halaman situs, jangan
            // menutup apa pun. Menutup Mini App di sini berarti pengguna
            // kehilangan halamannya tanpa mendapat gantinya.
            if (cadangan) window.location.href = cadangan;

            return;
        }

        setTimeout(function () {
            try { tg.close(); } catch (err) {}
        }, 200);
    }

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

            // Cadangannya halaman episode di situs — tombolnya tetap berguna
            // bila tautan botnya ditolak, bukan diam saja.
            bukaChatBot(pintasan, a.href);

            return;
        }

        var href = a.getAttribute('href') || '';

        if (!/^(https?:\/\/(t\.me|telegram\.me)\/|tg:\/\/)/i.test(href)) return;

        // Hanya dicegat kalau ada jalan penggantinya. Kalau SDK-nya versi
        // lama dan openTelegramLink tidak ada, biarkan perilaku bawaan —
        // lebih baik tautan biasa daripada klik yang kita telan sendiri.
        if (!tg.openTelegramLink) return;

        e.preventDefault();

        // Tanpa cadangan: href-nya sendiri sudah tautan t.me, dan mengarahkan
        // webview ke sana hanya menghasilkan halaman "buka di aplikasi".
        bukaChatBot(a.href, null);
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

    /*
    | Tujuan dari tautan ?startapp=
    | -----------------------------
    | Postingan channel tidak boleh lagi mengantar orang ke browser, dan
    | tombol `web_app` yang biasa dipakai membuka Mini App dilarang Telegram
    | di luar chat pribadi. Yang tersisa adalah tautan
    | t.me/<bot>/<app>?startapp=<tujuan>, yang selalu membuka Mini App di
    | HALAMAN AWAL — parameternya tidak diteruskan Telegram sebagai alamat,
    | melainkan dititipkan sebagai start_param di dalam initData.
    |
    | Jadi pemetaan tujuan ke halaman dikerjakan di sini. Pasangannya ada di
    | App\Support\TelegramDeepLink (konstanta APP_*); menambah satu di sana
    | tanpa menambahnya di sini menghasilkan tombol yang membuka beranda dan
    | terlihat seperti fitur yang belum jadi.
    */
    function tujuanStartapp() {
        var tujuan = (tg.initDataUnsafe && tg.initDataUnsafe.start_param) || '';

        if (!tujuan) return false;

        var peta = {
            'cari':    @json(route('web.search')),
            'request': @json(route('web.request.index')),
            'vip':     @json(route('web.membership'))
        };

        var alamat = peta[tujuan];

        if (!alamat) return false;

        /*
        | Sekali saja per sesi Mini App
        | -----------------------------
        | start_param TIDAK hilang setelah dipakai. Ia melekat pada sesi Mini
        | App dan terbaca lagi di SETIAP halaman yang dibuka sesudahnya.
        |
        | Tanpa penanda ini, orang yang masuk lewat ?startapp=cari lalu
        | menekan Beranda langsung terlempar kembali ke pencarian — dan
        | terlempar lagi setiap kali ia mencoba, sampai Mini App-nya ditutup.
        | Memeriksa "apakah sudah di halaman tujuan" saja tidak cukup, karena
        | justru halaman yang dituju penggunalah yang berbeda.
        |
        | sessionStorage, bukan localStorage: umurnya sepanjang webview.
        | Menutup lalu membuka Mini App lagi lewat tautan yang sama harus
        | tetap mengantar ke halamannya. Yang disimpan nilai tujuannya, bukan
        | sekadar "sudah" — supaya tautan ?startapp=request yang ditekan
        | belakangan di sesi yang sama tetap dilayani.
        */
        var kunci = 'tg-startapp';

        try {
            if (window.sessionStorage.getItem(kunci) === tujuan) return false;

            window.sessionStorage.setItem(kunci, tujuan);
        } catch (e) {
            // Tidak ada tempat mengingat berarti tidak ada cara berhenti.
            // Membiarkannya berpindah di sini akan mengurung penggunanya di
            // satu halaman; lebih baik tautannya sekadar membuka beranda.
            lapor('sessionStorage tidak tersedia, startapp diabaikan.');

            return false;
        }

        // Sudah berada di halaman yang dituju — tidak ada yang perlu
        // dikerjakan, dan memuat ulang halaman yang sama hanya mengedipkannya.
        if (window.location.pathname === new URL(alamat, window.location.origin).pathname) {
            return false;
        }

        window.location.replace(alamat);

        return true;
    }

    // Pindah halaman dulu, baru sisanya. Login otomatis di bawah diakhiri
    // `location.replace(location.href)`, dan kalau keduanya berjalan
    // bersamaan yang menang adalah yang belakangan: orangnya kembali ke
    // beranda persis setelah halaman yang benar mulai terbuka. Halaman
    // tujuan menjalankan berkas ini lagi dan login di sana.
    if (tujuanStartapp()) return;

    /*
    | Login dijalankan SEBELUM urusan kosmetik di bawahnya.
    |
    | Kunci orientasi, warna header, safe area, dan tombol kembali tidak
    | dibutuhkan siapa pun sampai halamannya benar. Menaruhnya lebih dulu
    | berarti permintaan login baru berangkat setelah semua itu selesai —
    | penundaan yang tidak besar, tapi tepat berada di dalam detik-detik
    | ketika pengguna sedang menatap halaman milik akun yang salah.
    */
    /*
    | Login otomatis: kirim initData yang sudah ditandatangani Telegram.
    |
    | Dikirim SELALU, termasuk ketika halaman ini sudah punya sesi login.
    | Dulu blok ini dibungkus direktif guest milik Blade, jadi halaman yang
    | sudah login tidak pernah menanyakan apa pun — dan itulah sebabnya sesi
    | menempel pada akun sebelumnya: Telegram memakai satu webview untuk semua akun di perangkat
    | itu, sehingga akun B membuka Mini App sambil membawa cookie milik akun
    | A. Tanpa initData dikirim, tidak ada satu pun pihak yang tahu bahwa
    | pemiliknya sudah berganti.
    |
    | Yang memutuskan perlu-tidaknya sesi diganti adalah server — lihat
    | TelegramMiniAppController. Halaman ini hanya menuruti jawabannya.
    */
    (function login() {
        var sudahLogin = @json(auth()->check());

        /*
        | Menutupi halaman akun lama SEKARANG, bukan setelah server menjawab.
        |
        | Pergantian akun butuh satu perjalanan bolak-balik ke server lalu
        | satu kali muat ulang. Selama itu — sedetik dua detik di jaringan
        | seluler — yang terpampang adalah halaman lengkap milik akun
        | SEBELUMNYA: namanya, riwayatnya, status VIP-nya. Itu yang terasa
        | sebagai "tidak langsung berganti"; pergantiannya sendiri sudah
        | jalan sejak awal.
        |
        | `initDataUnsafe` dipakai di sini justru karena namanya: ia TIDAK
        | diverifikasi, dan tidak boleh dipakai memutuskan siapa yang login.
        | Yang diputuskan di sini cuma "perlukah tirai dipasang" — keputusan
        | kosmetik yang salahnya paling banter satu tirai yang tidak perlu.
        | Identitas tetap ditentukan server dari initData bertanda tangan.
        */
        var akunSekarang = (tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.id)
            ? String(tg.initDataUnsafe.user.id)
            : '';

        var akunSesi = @json((string) (auth()->user()?->telegram_id ?? ''));

        var berganti = sudahLogin && akunSesi !== '' && akunSekarang !== '' && akunSesi !== akunSekarang;

        if (berganti) {
            document.getElementById('tg-boot').textContent = 'Berganti akun…';
        }

        // Sesi yang sudah benar tidak perlu dikedipkan setiap kali halaman
        // dibuka — dan itu mayoritas pembukaan.
        if (!sudahLogin || berganti) {
            body.classList.add('tg-authenticating');
        }

        /*
        | Sesi yang jelas-jelas sudah milik akun ini: tidak usah bertanya.
        |
        | Perbandingan yang sama persis dengan yang akan dikerjakan server,
        | jadi jawabannya sudah pasti "already" — satu permintaan POST di
        | setiap pembukaan halaman, hanya untuk mendengar bahwa tidak ada
        | yang berubah.
        |
        | Memakai initDataUnsafe untuk MELEWATI pemeriksaan aman di sini,
        | dan hanya di sini: yang didapat orang yang memalsukannya cuma
        | mempertahankan sesinya sendiri — sesi yang cookie-nya sudah ada di
        | tangannya. Tidak ada akun lain yang bisa dimasuki lewat jalan ini.
        | Perhatikan syarat `akunSesi !== ''`: sesi yang bukan dari Telegram
        | (mis. admin yang masuk lewat kata sandi) tidak punya pembanding,
        | jadi ia tetap ditanyakan ke server.
        */
        if (sudahLogin && !berganti && akunSesi !== '' && akunSekarang !== '') {
            return;
        }

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
                // Sesi memang sudah milik akun yang sedang membuka Mini App.
                // Memuat ulang di sini berarti setiap pembukaan halaman
                // dimuat dua kali, tanpa satu pun yang berubah.
                if (data.already) {
                    body.classList.remove('tg-authenticating');

                    return;
                }

                // replace(), bukan reload(): reload mengulang POST kalau
                // halaman ini sendiri hasil kiriman form.
                window.location.replace(window.location.href);
                return;
            }

            /*
            | Server sudah memutus sesi akun lama, tapi akun barunya ditolak
            | (diblokir atau dinonaktifkan). Halaman yang sedang terbuka masih
            | menampilkan nama dan isi milik akun lama, jadi ia harus diambil
            | ulang — kalau tidak, yang terlihat adalah halaman milik orang
            | lain yang tombol-tombolnya sudah tidak berfungsi.
            */
            if (data && data.reset) {
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

})();
</script>
