import './bootstrap';

import navbar from './web/navbar';
import heroSlider from './web/hero-slider';
import animation from './web/animation';
import player from './player';
import admin from './admin';

document.addEventListener('DOMContentLoaded', () => {
    navbar();
    heroSlider();
    animation();
    player();
    admin();
});
