/** Memunculkan section saat masuk viewport. */
export default function animation() {
    const targets = document.querySelectorAll('.section');

    if (!targets.length || !('IntersectionObserver' in window)) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -10% 0px' });

    targets.forEach((el) => observer.observe(el));
}
