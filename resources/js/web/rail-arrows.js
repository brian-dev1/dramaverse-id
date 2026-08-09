/**
 * Panah geser untuk daftar drama mendatar (.rail).
 *
 * Panah hanya muncul bila isinya memang melebihi lebar layar, dan
 * masing-masing disembunyikan saat sudah mentok di ujungnya.
 */
export default function railArrows() {
    document.querySelectorAll('.rail-wrap').forEach((wrap) => {
        const rail = wrap.querySelector('.rail');
        const prev = wrap.querySelector('.rail-arrow-prev');
        const next = wrap.querySelector('.rail-arrow-next');

        if (!rail || !prev || !next) {
            return;
        }

        const perbarui = () => {
            const bisaGeser = rail.scrollWidth - rail.clientWidth > 4;
            const posisi = Math.round(rail.scrollLeft);
            const maksimal = Math.round(rail.scrollWidth - rail.clientWidth);

            prev.hidden = !bisaGeser || posisi <= 2;
            next.hidden = !bisaGeser || posisi >= maksimal - 2;
        };

        const geser = (arah) => {
            rail.scrollBy({
                left: arah * Math.round(rail.clientWidth * 0.8),
                behavior: 'smooth',
            });
        };

        prev.addEventListener('click', () => geser(-1));
        next.addEventListener('click', () => geser(1));
        rail.addEventListener('scroll', perbarui, { passive: true });
        window.addEventListener('resize', perbarui);

        // Gambar poster memengaruhi lebar total, jadi hitung ulang saat selesai muat.
        window.addEventListener('load', perbarui);

        perbarui();
    });
}
