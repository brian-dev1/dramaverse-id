/**
 * Menambah bayangan pada navbar saat halaman digulir.
 *
 * Event `scroll` bisa terpicu puluhan kali per frame di ponsel. Sebelumnya
 * setiap picuan langsung membaca window.scrollY lalu memanggil classList
 * .toggle — pembacaan tata letak di tengah gulir yang memaksa browser
 * menghitung ulang posisi sebelum sempat menggambar frame berikutnya.
 *
 * Sekarang picuan hanya menandai "perlu diperiksa", dan pemeriksaannya
 * dijadwalkan ke requestAnimationFrame: maksimal sekali per frame, tepat di
 * saat browser memang sudah bersiap menggambar.
 */
export default function navbar() {
    const bar = document.querySelector('.navbar');

    if (!bar) return;

    let terjadwal = false;
    let aktif = null;

    const periksa = () => {
        terjadwal = false;

        const seharusnya = window.scrollY > 20;

        // Kelasnya hanya disentuh saat nilainya benar-benar berubah, bukan
        // setiap frame — menulis ke classList memicu penghitungan ulang gaya.
        if (seharusnya !== aktif) {
            aktif = seharusnya;
            bar.classList.toggle('scrolled', seharusnya);
        }
    };

    const onScroll = () => {
        if (terjadwal) return;
        terjadwal = true;
        requestAnimationFrame(periksa);
    };

    periksa();
    window.addEventListener('scroll', onScroll, { passive: true });
}
