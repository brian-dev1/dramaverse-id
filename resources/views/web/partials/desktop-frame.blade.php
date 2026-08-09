{{--
    Bingkai ponsel untuk layar lebar.

    Di desktop, halaman dimuat ulang di dalam <iframe> selebar 390px.
    Iframe punya viewport sendiri, jadi seluruh @media (max-width: ...)
    yang sudah ada ikut aktif — tampilannya identik dengan mini app
    Telegram, tanpa mengubah satu baris pun CSS yang sudah ada.

    Tidak aktif bila:
    - halaman sudah berada di dalam iframe (mencegah bingkai bertumpuk),
    - dibuka di dalam Telegram,
    - lebar layar memang sudah sempit,
    - URL memakai ?noframe=1 (jalan keluar untuk memeriksa versi asli),
    - halaman admin.
--}}
@unless (request()->is('admin*'))
<script>
(function () {
    try {
        if (window.top !== window.self) return;
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) return;
        if (location.search.indexOf('noframe=1') !== -1) return;
        if (!window.matchMedia('(min-width: 760px)').matches) return;

        var src = location.href;

        document.open();
        document.write(
            '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">' +
            '<title>' + document.title + '</title>' +
            '<meta name="robots" content="noindex">' +
            '<style>' +
            'html,body{margin:0;height:100%;background:#0B0710;}' +
            'body{display:flex;align-items:center;justify-content:center;}' +
            '.dv-phone{width:390px;height:min(844px,calc(100vh - 32px));' +
            'border:1px solid rgba(255,255,255,.09);border-radius:22px;overflow:hidden;' +
            'box-shadow:0 30px 90px -30px rgba(0,0,0,.9);background:#0B0710;}' +
            '.dv-phone iframe{width:100%;height:100%;border:0;display:block;}' +
            '</style></head><body>' +
            '<div class="dv-phone"><iframe src="' + src.replace(/"/g, '&quot;') + '"' +
            ' title="DramaVerse ID"' +
            ' allow="fullscreen; autoplay; picture-in-picture; encrypted-media"' +
            ' allowfullscreen></iframe></div>' +
            '</body></html>'
        );
        document.close();
    } catch (e) {
        /* Kalau apa pun gagal, halaman biasa tetap tampil. */
    }
})();
</script>
@endunless
