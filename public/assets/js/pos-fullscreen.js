/*
 * Full screen for the till.
 *
 * The kitchen board goes full screen by taking its own element into the top
 * layer (kds.js). The till cannot: only that element is drawn in that mode, and
 * everything the till opens lives outside it - the camera scanner, a customer
 * dialog, the receipt, the toast that says a sale went through. They would all
 * open invisibly behind a full-screen counter.
 *
 * So the whole document goes full screen, and the admin chrome (sidebar, header,
 * footer) is put away with a class on <body>. Same result for the cashier - the
 * till fills the screen - with everything still able to appear on top of it.
 *
 * The browser only allows this from a click or a key press, which is why it is
 * a button and not something the page does on its own. Escape leaves it, as it
 * does everywhere; so does the button.
 *
 * Staying in it. A browser drops full screen whenever the page itself changes:
 * holding a sale reloads the till, resuming one navigates to it, a printed
 * receipt opens a tab. For a cashier that would mean pressing the button again
 * after every held sale, so "I want full screen" is remembered for the tab
 * (sessionStorage). The page that loads next already has its chrome put away -
 * no flash of the sidebar - and takes the browser back into full screen on the
 * first tap or key press, which is the earliest the browser permits it. Pressing
 * Escape or the button is what ends it, and that forgets it.
 *
 * Where the browser has no Fullscreen API (an iPhone) the button stays hidden
 * rather than offering something that cannot work.
 */
(function (window, document) {
    'use strict';

    var KEY = 'pos.fullscreen';

    var root = document.documentElement;
    var request = root.requestFullscreen || root.webkitRequestFullscreen;
    var leave = document.exitFullscreen || document.webkitExitFullscreen;

    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-fullscreen-toggle]'));

    if (!request || !leave || buttons.length === 0) { return; }

    var unloading = false;
    var restoreArmed = false;

    function remembered() {
        try { return window.sessionStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }

    function remember(on) {
        try {
            if (on) { window.sessionStorage.setItem(KEY, '1'); } else { window.sessionStorage.removeItem(KEY); }
        } catch (e) { /* private mode: it just will not carry over a reload */ }
    }

    function isOn() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    /* What the screen looks like: chrome put away, button saying how to leave. */
    function paint(on) {
        document.body.classList.toggle('pos-fullscreen', on);

        buttons.forEach(function (button) {
            button.setAttribute('aria-pressed', String(on));

            var enter = button.querySelector('[data-fs-enter]');
            var exit = button.querySelector('[data-fs-exit]');
            if (enter) { enter.hidden = on; }
            if (exit) { exit.hidden = !on; }
        });
    }

    function enter() {
        var result = request.call(root);

        if (result && typeof result.catch === 'function') {
            result.catch(function () {
                // Refused (no user gesture, or the browser said no): do not leave the
                // chrome hidden behind a screen that is not actually full.
                remember(false);
                paint(false);
                if (window.Toast) { window.Toast.error('This browser would not go full screen.'); }
            });
        }
    }

    function exit() {
        remember(false);
        paint(false);
        if (isOn()) { leave.call(document); }
    }

    function toggle() {
        if (isOn() || remembered()) {
            exit();
        } else {
            remember(true);
            paint(true);
            enter();
        }
    }

    /*
     * A reload or a navigation took us out of full screen. The next gesture -
     * the first tap, or the Enter from a scanner - is allowed to take us back.
     */
    function armRestore() {
        if (restoreArmed) { return; }
        restoreArmed = true;

        function retry(event) {
            // Pressing the button to LEAVE must not be the gesture that re-enters.
            if (event && event.target && event.target.closest && event.target.closest('[data-fullscreen-toggle]')) { return; }

            document.removeEventListener('pointerdown', retry, true);
            document.removeEventListener('keydown', retry, true);
            restoreArmed = false;

            if (remembered() && !isOn()) { enter(); }
        }

        document.addEventListener('pointerdown', retry, true);
        document.addEventListener('keydown', retry, true);
    }

    function onChange() {
        if (isOn()) {
            remember(true);
            paint(true);
            return;
        }

        // Out of full screen. If the page is going away this is the browser
        // tidying up and the choice must survive; if the page is staying, the
        // person pressed Escape and meant it.
        if (unloading) { return; }

        remember(false);
        paint(false);
    }

    window.addEventListener('beforeunload', function () { unloading = true; });
    window.addEventListener('pagehide', function () { unloading = true; });
    window.addEventListener('pageshow', function () { unloading = false; });

    buttons.forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function (event) {
            event.preventDefault();
            toggle();
        });
    });

    document.addEventListener('fullscreenchange', onChange);
    document.addEventListener('webkitfullscreenchange', onChange);

    if (isOn()) {
        paint(true);
    } else if (remembered()) {
        paint(true);
        armRestore();
    } else {
        paint(false);
    }
})(window, document);
