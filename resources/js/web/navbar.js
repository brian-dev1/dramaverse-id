/** Menambah bayangan pada navbar saat halaman digulir. */
export default function navbar() {
    const bar = document.querySelector('.navbar');

    if (!bar) return;

    const onScroll = () => bar.classList.toggle('scrolled', window.scrollY > 20);

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
}
