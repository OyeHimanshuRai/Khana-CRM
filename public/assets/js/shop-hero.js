(() => {
    const carousels = document.querySelectorAll('[data-shop-carousel]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    carousels.forEach((carousel) => {
        const slides = Array.from(carousel.querySelectorAll('[data-shop-slide]'));
        const dots = Array.from(carousel.querySelectorAll('[data-shop-slide-to]'));

        if (slides.length < 2) {
            return;
        }

        let current = slides.findIndex((slide) => slide.classList.contains('is-active'));
        let timer = null;

        const show = (next) => {
            current = (next + slides.length) % slides.length;

            slides.forEach((slide, index) => {
                const active = index === current;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
            });

            dots.forEach((dot, index) => {
                dot.setAttribute('aria-current', index === current ? 'true' : 'false');
            });
        };

        const stop = () => {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const start = () => {
            stop();

            if (!reducedMotion && !document.hidden) {
                timer = window.setInterval(() => show(current + 1), 6000);
            }
        };

        carousel.querySelector('[data-shop-slide-prev]')?.addEventListener('click', () => {
            show(current - 1);
            start();
        });

        carousel.querySelector('[data-shop-slide-next]')?.addEventListener('click', () => {
            show(current + 1);
            start();
        });

        dots.forEach((dot) => {
            dot.addEventListener('click', () => {
                show(Number(dot.dataset.shopSlideTo));
                start();
            });
        });

        carousel.addEventListener('mouseenter', stop);
        carousel.addEventListener('mouseleave', start);
        carousel.addEventListener('focusin', stop);
        carousel.addEventListener('focusout', (event) => {
            if (!carousel.contains(event.relatedTarget)) {
                start();
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stop();
            } else {
                start();
            }
        });

        show(current < 0 ? 0 : current);
        start();
    });
})();
