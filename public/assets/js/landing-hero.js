/*
 | The landing hero's background slides.
 |
 | The slides are stacked <picture>s in [data-hero-slides]; CSS fades whichever
 | carries .is-on. This only moves that class along every few seconds. Loaded
 | only when there are two or more slides, and it stands still for anybody who
 | has asked their system for reduced motion - the first slide is already on,
 | so the page looks finished either way.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-hero-slides]');
    if (!root) return;

    var slides = root.querySelectorAll('.lp-hero-slide');
    if (slides.length < 2) return;

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var current = 0;

    setInterval(function () {
        // A hidden tab keeps its place rather than racing through slides nobody saw.
        if (document.hidden) return;

        slides[current].classList.remove('is-on');
        current = (current + 1) % slides.length;
        slides[current].classList.add('is-on');
    }, 6000);
})();
