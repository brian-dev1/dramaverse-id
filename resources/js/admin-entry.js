/*
| Entry JavaScript panel admin.
|
| Isinya sama dengan resources/js/app.js ditambah modul admin. Panel admin
| tetap memakai sebagian komponen sisi web (brand, ikon, paginasi), jadi
| modul web ikut dimuat di sini — yang dipisah hanya arah sebaliknya.
*/

import './bootstrap';

import navbar from './web/navbar';
import heroSlider from './web/hero-slider';
import animation from './web/animation';
import railArrows from './web/rail-arrows';
import player from './player';
import admin from './admin';

function mulai() {
    navbar();
    heroSlider();
    animation();
    railArrows();
    player();
    admin();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mulai);
} else {
    mulai();
}
