/**
 * Panah geser untuk daftar drama mendatar (.rail).
 *
 * Panah hanya muncul bila isinya memang melebihi lebar layar, dan
 * masing-masing disembunyikan saat sudah mentok di ujungnya.
 *
 * Catatan performa
 * ----------------
 * `perbarui()` membaca scrollWidth/clientWidth/scrollLeft — ketiganya memaksa
 * browser menyelesaikan perhitungan tata letak sebelum bisa menjawab. Dulu ia
 * dipanggil langsung dari event `scroll`, yang di ponsel bisa terpicu puluhan
 * kali per frame: setiap picuan menghentikan gulir sebentar untuk mengukur
 * ulang. Sekarang pembacaannya dijadwalkan ke requestAnimationFrame sehingga
 * paling banyak sekali per frame.
 *
 * Selain itu tiap rail dulu memasang listener `resize` dan `load` sendiri di
 * window. Halaman beranda punya beberapa rail, jadi satu kali putar layar
 * memicu beberapa pengukuran beruntun. ResizeObserver menggantikannya: ia
 * hanya melapor kalau elemen yang diamati memang berubah ukuran, termasuk
 * ketika poster selesai dimuat dan menambah lebar isi.
 */
export default function railArrows() {
    document.querySelectorAll('.rail-wrap').forEach((wrap) => {
        const rail = wrap.querySelector('.rail');
        const prev = wrap.querySelector('.rail-arrow-prev');
        const next = wrap.querySelector('.rail-arrow-next');

        if (!rail || !prev || !next) {
            return;
        }

        let terjadwal = false;

        const perbarui = () => {
            terjadwal = false;

            const bisaGeser = rail.scrollWidth - rail.clientWidth > 4;
            const posisi = Math.round(rail.scrollLeft);
            const maksimal = Math.round(rail.scrollWidth - rail.clientWidth);

            const sembunyiPrev = !bisaGeser || posisi <= 2;
            const sembunyiNext = !bisaGeser || posisi >= maksimal - 2;

            // Hanya ditulis saat nilainya berubah; menulis properti `hidden`
            // yang nilainya sudah sama tetap menandai gaya perlu dihitung ulang.
            if (prev.hidden !== sembunyiPrev) prev.hidden = sembunyiPrev;
            if (next.hidden !== sembunyiNext) next.hidden = sembunyiNext;
        };

        const jadwalkan = () => {
            if (terjadwal) return;
            terjadwal = true;
            requestAnimationFrame(perbarui);
        };

        const geser = (arah) => {
            rail.scrollBy({
                left: arah * Math.round(rail.clientWidth * 0.8),
                behavior: 'smooth',
            });
        };

        prev.addEventListener('click', () => geser(-1));
        next.addEventListener('click', () => geser(1));
        rail.addEventListener('scroll', jadwalkan, { passive: true });

        if ('ResizeObserver' in window) {
            const pengamat = new ResizeObserver(jadwalkan);
            pengamat.observe(rail);

            // Anak pertama diamati juga: lebar isi rail bertambah ketika
            // poster selesai dimuat, dan itu tidak mengubah ukuran rail-nya.
            if (rail.firstElementChild) pengamat.observe(rail.firstElementChild);
        } else {
            window.addEventListener('resize', jadwalkan);
            window.addEventListener('load', jadwalkan);
        }

        perbarui();
    });
}
