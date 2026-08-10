/*
| Entry JavaScript sisi web / mini app.
|
| Modul panel admin (resources/js/admin.js, ~80 KB) SENGAJA tidak diimpor di
| sini. Ia hanya dipakai di layout admin dan sekarang punya entry sendiri di
| resources/js/admin-entry.js. Sebelumnya ia ikut terunduh di setiap halaman
| mini app padahal tidak ada satu pun elemen yang dicarinya di sana.
*/

/*
| resources/js/bootstrap.js sengaja TIDAK diimpor di sini.
|
| Isinya hanya `window.axios = axios` bawaan kerangka Laravel, dan axios tidak
| dipanggil di satu tempat pun — bukan di resources/js, bukan di satu pun blade.
| Semua permintaan ke server di sisi web memakai fetch() bawaan browser
| (lihat player.js dan partials/miniapp.blade.php). Pustakanya tetap terunduh
| 49 KB di setiap halaman hanya untuk duduk di window tanpa pernah dipanggil.
|
| Berkasnya tidak dihapus dan masih dimuat oleh entry admin, jadi window.axios
| tetap ada di panel admin kalau suatu saat dibutuhkan. Kalau sisi web nanti
| memerlukannya, impor di berkas yang memakainya — bukan di entry global.
*/

import navbar from './web/navbar';
import heroSlider from './web/hero-slider';
import animation from './web/animation';
import railArrows from './web/rail-arrows';
import player from './player';

function mulai() {
    navbar();
    heroSlider();
    animation();
    railArrows();
    player();
}

// Skrip dimuat dengan type="module" (otomatis defer), jadi pada saat baris
// ini jalan DOM biasanya sudah siap dan event DOMContentLoaded sudah lewat.
// Tanpa pemeriksaan ini, listener-nya tidak akan pernah terpanggil.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mulai);
} else {
    mulai();
}
