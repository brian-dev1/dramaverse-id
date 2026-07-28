/**
 * Rotasi indikator hero.
 * Markup hero merender satu slide sekaligus; titik indikator berputar
 * untuk memberi kesan hidup tanpa memuat ulang gambar.
 */
export default function heroSlider() {
    const dots = document.querySelectorAll('.hero-dots span');

    if (dots.length < 2) return;

    let active = 0;

    setInterval(() => {
        dots[active].classList.remove('active');
        active = (active + 1) % dots.length;
        dots[active].classList.add('active');
    }, 3500);
}
