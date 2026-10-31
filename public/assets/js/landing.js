/*
| The landing page's carousel.
|
| An enhancement, never a dependency. The track it drives is a scroll-snapping
| strip, so with this file blocked the slides are still all there and still
| swipeable - what this adds is the furniture a strip cannot draw for itself:
| dots, arrows and an autoplay that knows when to stop.
|
| Every control is written by this file rather than sitting in the Blade, so a
| visitor whose JavaScript failed is never shown a button that does nothing.
|
| Slides off to the side are left in the document rather than marked inert:
| inert takes the pointer events with it, and a swipe that begins on the slide
| coming into view is how most people move a carousel on a phone.
|
| Pairs with resources/views/landing/index.blade.php and landing.css.
*/
(function (window, document) {
    'use strict';

    var INTERVAL = 6000;

    function slides(root) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-lp-slide]'));
    }

    /**
     * How far into the track a slide sits.
     *
     * Measured from the first slide rather than from the track, because the
     * two do not share an offsetParent: the slides are positioned and the
     * track is not, so subtracting the track's own offset lands the carousel
     * a few dozen pixels short of every slide. scrollLeft is counted from the
     * content's left edge, which is exactly where the first slide begins.
     */
    function offsetOf(items, index) {
        return items[index].offsetLeft - items[0].offsetLeft;
    }

    /**
     * Which slide is in front of the reader right now.
     *
     * Read off scroll position rather than trusted to a variable, because the
     * reader can swipe the track themselves and a remembered index would then
     * be describing a slide nobody is looking at.
     */
    function current(track, items) {
        var middle = track.scrollLeft + track.clientWidth / 2;
        var best = 0;
        var shortest = Infinity;

        items.forEach(function (item, i) {
            var distance = Math.abs(offsetOf(items, i) + item.offsetWidth / 2 - middle);

            if (distance < shortest) {
                shortest = distance;
                best = i;
            }
        });

        return best;
    }

    function go(track, items, index) {
        var wanted = (index + items.length) % items.length;

        track.scrollTo({
            left: offsetOf(items, wanted),
            behavior: reduced() ? 'auto' : 'smooth',
        });

        return wanted;
    }

    function reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function init(root) {
        var track = root.querySelector('[data-lp-track]');
        var ui = root.querySelector('[data-lp-ui]');
        var items = slides(root);

        // One slide is a panel, not a carousel: no dots, no arrows, no timer.
        if (!track || !ui || items.length < 2) {
            return;
        }

        /* ------------------------------------------------------ controls */

        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'lp-banner-arrow is-prev';
        prev.setAttribute('aria-label', 'Previous slide');
        prev.innerHTML = '<span aria-hidden="true">&#8249;</span>';

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'lp-banner-arrow is-next';
        next.setAttribute('aria-label', 'Next slide');
        next.innerHTML = '<span aria-hidden="true">&#8250;</span>';

        var dots = document.createElement('div');
        dots.className = 'lp-banner-dots';
        dots.setAttribute('role', 'tablist');
        dots.setAttribute('aria-label', 'Choose a slide');

        var buttons = items.map(function (item, i) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'lp-banner-dot';
            dot.setAttribute('role', 'tab');
            dot.setAttribute('aria-label', 'Slide ' + (i + 1));
            dot.addEventListener('click', function () {
                stop();
                paint(go(track, items, i));
            });
            dots.appendChild(dot);

            return dot;
        });

        prev.addEventListener('click', function () {
            stop();
            paint(go(track, items, current(track, items) - 1));
        });

        next.addEventListener('click', function () {
            stop();
            paint(go(track, items, current(track, items) + 1));
        });

        ui.appendChild(prev);
        ui.appendChild(dots);
        ui.appendChild(next);
        root.classList.add('is-live');

        /* --------------------------------------------------------- state */

        /**
         * Light the dot for a slide.
         *
         * Takes the index when the caller already knows it - a click on an
         * arrow or a dot - and works it out from scroll position otherwise.
         * Without that, a smooth scroll would leave the dots describing the
         * slide the reader has just left until the animation finished.
         */
        function paint(index) {
            var at = typeof index === 'number' ? index : current(track, items);

            buttons.forEach(function (dot, i) {
                var on = i === at;
                dot.classList.toggle('is-on', on);
                dot.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        var frame = null;

        track.addEventListener('scroll', function () {
            if (frame) {
                return;
            }

            frame = window.requestAnimationFrame(function () {
                frame = null;
                paint();
            });
        });

        /* ------------------------------------------------------ autoplay */

        var timer = null;

        function start() {
            /*
             | Nothing moves on its own for somebody who asked for less motion,
             | and nothing moves while the tab is in the background either -
             | a carousel that advanced six times behind another window would
             | leave the reader on a slide they never saw arrive.
             */
            if (timer || reduced() || document.hidden) {
                return;
            }

            timer = window.setInterval(function () {
                paint(go(track, items, current(track, items) + 1));
            }, INTERVAL);
        }

        function stop() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        // Hovering, focusing or touching it is somebody reading it.
        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);
        root.addEventListener('focusin', stop);
        root.addEventListener('focusout', start);
        root.addEventListener('touchstart', stop, { passive: true });

        document.addEventListener('visibilitychange', function () {
            document.hidden ? stop() : start();
        });

        /* ------------------------------------------------------ keyboard */

        root.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                stop();
                paint(go(track, items, current(track, items) - 1));
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                stop();
                paint(go(track, items, current(track, items) + 1));
            }
        });

        paint();
        start();
    }

    function boot() {
        document.querySelectorAll('[data-lp-slider]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
