/**
 * Rotasi indikator hero.
 * Markup hero merender satu slide sekaligus; titik indikator berputar
 * untuk memberi kesan hidup tanpa memuat ulang gambar.
 *
 * Dua hal yang dijaga di sini:
 *
 * 1. Timer berhenti saat halaman tidak terlihat. Di Telegram Mini App halaman
 *    tetap hidup ketika pengguna berpindah ke chat lain, jadi tanpa ini timer
 *    terus membangunkan CPU dan menyedot baterai di latar belakang.
 * 2. Timer juga berhenti begitu hero tidak lagi di layar. Menggulir ke bawah
 *    membuat titik indikator tidak terlihat, tetapi dulu ia tetap berganti
 *    kelas tiap 3,5 detik — menggambar ulang sesuatu yang tidak dilihat siapa
 *    pun, tepat saat gulir sedang butuh CPU-nya.
 */
export default function heroSlider() {
    const dots = document.querySelectorAll('.hero-dots span');

    if (dots.length < 2) return;

    const wadah = dots[0].parentElement;

    let active = 0;
    let timer = null;
    let terlihat = true;

    const putar = () => {
        dots[active].classList.remove('active');
        active = (active + 1) % dots.length;
        dots[active].classList.add('active');
    };

    const jalan = () => {
        if (timer || !terlihat || document.hidden) return;
        timer = setInterval(putar, 3500);
    };

    const berhenti = () => {
        if (!timer) return;
        clearInterval(timer);
        timer = null;
    };

    document.addEventListener('visibilitychange', () => {
        document.hidden ? berhenti() : jalan();
    });

    if (wadah && 'IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            terlihat = entries[0].isIntersecting;
            terlihat ? jalan() : berhenti();
        }).observe(wadah);
    }

    jalan();
}
