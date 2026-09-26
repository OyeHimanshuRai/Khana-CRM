/*
|------------------------------------------------------------------------------
| Kitchen display (§9)
|------------------------------------------------------------------------------
| Four small jobs, none of which the server can do:
|
|   1. re-fetch the board every few seconds          (§9 real-time feed)
|   2. ring when a ticket lands that was not there   (§9 sound / notification)
|   3. tick the timers between fetches               (§9 order timer)
|   4. full screen, for the wall
|
| The fetch itself is AjaxList's - the board is an ordinary swappable fragment
| and the bump buttons are ordinary data-ajax forms, so everything here is
| additions to machinery the rest of the admin already uses.
|
| Progressive enhancement: without this file the board is a page that shows
| what was true when it was loaded, and the toolbar says so out loud rather
| than leaving a cook watching a screen that will never change.
*/
(function (window, document) {
    'use strict';

    var STORE_SOUND = 'erp.kds.sound';

    var root = null;      // the [data-kds] card
    var timer = null;     // the poll
    var ticker = null;    // the once-a-second clock
    var latest = 0;       // highest ticket id seen, for "is this new?"
    var sound = false;
    var audio = null;

    /* --------------------------------------------------------------- sound */

    /*
     | Synthesised rather than a file. A two-tone blip is about forty lines of
     | WebAudio and needs no asset, no cache-busting and no decision about
     | which format to ship - and a kitchen only ever wants to know that
     | *something* arrived.
     */
    function beep() {
        if (!sound) { return; }

        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) { return; }

            audio = audio || new Ctx();

            // Browsers suspend a context created before any interaction.
            if (audio.state === 'suspended') { audio.resume(); }

            [0, 0.18].forEach(function (offset, i) {
                var osc = audio.createOscillator();
                var gain = audio.createGain();

                osc.type = 'sine';
                osc.frequency.value = i === 0 ? 880 : 1180;

                // Shaped, not switched: a square edge on a cheap wall speaker
                // is a click before it is a note.
                gain.gain.setValueAtTime(0.0001, audio.currentTime + offset);
                gain.gain.exponentialRampToValueAtTime(0.22, audio.currentTime + offset + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + offset + 0.16);

                osc.connect(gain);
                gain.connect(audio.destination);
                osc.start(audio.currentTime + offset);
                osc.stop(audio.currentTime + offset + 0.18);
            });
        } catch (e) {
            /* A screen with no audio output is not a broken screen. */
        }
    }

    /*
     | No count, because there is not an honest one to give: the board knows
     | the highest ticket id it has seen, not how many arrived since. Saying
     | "3 tickets" when it means "at least one" is worse than saying nothing.
     */
    function notify() {
        if (!sound || !('Notification' in window) || Notification.permission !== 'granted') { return; }

        try {
            var n = new Notification('New kitchen ticket', {
                body: 'Something just landed on the board.',
                tag: 'kds-new'   // replaces, so a busy service is one badge
            });

            window.setTimeout(function () { n.close(); }, 8000);
        } catch (e) {}
    }

    function setSound(on, ask) {
        sound = !!on;

        try { window.localStorage.setItem(STORE_SOUND, sound ? '1' : '0'); } catch (e) {}

        document.querySelectorAll('[data-kds-sound]').forEach(function (button) {
            button.setAttribute('aria-pressed', sound ? 'true' : 'false');
            var label = button.querySelector('[data-kds-sound-label]');
            if (label) { label.textContent = sound ? 'Sound on' : 'Sound off'; }
        });

        /*
         | Permission is asked for here and nowhere else, because this is the
         | one moment there is a user gesture to hang it off - and a page that
         | asks on load is a page everybody denies.
         */
        if (sound && ask && 'Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        if (sound && ask) { beep(); }
    }

    /* ---------------------------------------------------------- the timers */

    /*
     | Recomputed from the placed-at stamp the server wrote, never incremented
     | from the last value. A screen left open overnight, a laptop that slept,
     | a poll that failed for two minutes - all of them break a counter and
     | none of them break a subtraction.
     */
    function tick() {
        var now = Date.now() / 1000;

        document.querySelectorAll('[data-kds-ticket]').forEach(function (card) {
            var placed = parseInt(card.getAttribute('data-placed'), 10);
            if (!placed) { return; }

            var minutes = Math.max(0, Math.floor((now - placed) / 60));
            var label = card.querySelector('[data-kds-timer]');

            if (label) { label.textContent = minutes + 'm'; }

            var allowed = parseInt(card.getAttribute('data-allowed'), 10) || 15;

            card.classList.toggle('is-late', minutes >= allowed);
            card.classList.toggle('is-warm', minutes >= Math.ceil(allowed * 0.7) && minutes < allowed);
        });
    }

    /* ----------------------------------------------------------- the poll */

    function board() {
        return root ? root.querySelector('[data-kds-board]') : null;
    }

    function modalIsOpen() {
        return document.body.classList.contains('modal-open');
    }

    /**
     * Whether it is safe to swap the board out from under the room.
     *
     * Not while a dialog is open, and not while somebody is part-way through
     * typing or choosing something on the board - a recall reason half
     * written, a filter half changed.
     *
     * A focused *button* deliberately does not count. Focus stays on a bump
     * button after it is clicked, and treating that as busy would stop a wall
     * screen refreshing for the rest of the evening - which is the one failure
     * this whole file exists to prevent.
     */
    function busy() {
        if (modalIsOpen()) { return true; }

        var active = document.activeElement;

        return !!(active && root && root.contains(active)
            && active.matches('input, select, textarea'));
    }

    function poll() {
        if (!root || document.hidden || busy()) { return; }
        if (!window.AjaxList) { return; }

        window.AjaxList.reload(root);
    }

    /*
     | How often to poll, given whether a socket is working.
     |
     | The poll is never switched off, only slowed. A WebSocket that is
     | connected is not the same as a WebSocket that is delivering - a proxy
     | that silently drops frames, a Reverb worker wedged on one channel, a
     | tablet whose wifi dropped between the ping and the pong all leave a
     | socket that looks alive and says nothing. On a kitchen wall screen
     | that is a service's worth of missed tickets.
     |
     | So a live socket buys a slow heartbeat, not silence: worst case the
     | board is a minute stale instead of seven seconds, and the instant a
     | socket dies the real interval comes back.
     */
    function interval() {
        var every = parseInt(root.getAttribute('data-kds-every'), 10) || 7;

        var live = window.Realtime && window.Realtime.isLive();

        return live
            ? Math.max(30, every * 6) * 1000
            : Math.max(3, every) * 1000;
    }

    function start() {
        window.clearInterval(timer);
        timer = window.setInterval(poll, interval());
    }

    /**
     * After every swap: did anything arrive that was not there before?
     *
     * The highest ticket id, not the count. A ticket bumped away and a new
     * one landing inside the same seven seconds leaves the count unchanged,
     * and that is exactly the ring nobody can afford to miss.
     */
    function afterLoad() {
        var el = board();
        if (!el) { return; }

        var seen = parseInt(el.getAttribute('data-kds-latest'), 10) || 0;

        if (latest && seen > latest) {
            beep();
            notify();
        }

        // Never moves backwards: a filter that hides the newest ticket must
        // not make the next arrival ring twice.
        if (seen > latest) { latest = seen; }

        tick();
    }

    /* ---------------------------------------------------------------- boot */

    function init() {
        root = document.querySelector('[data-kds]');
        if (!root) { return; }

        var el = board();
        latest = el ? (parseInt(el.getAttribute('data-kds-latest'), 10) || 0) : 0;

        try { setSound(window.localStorage.getItem(STORE_SOUND) === '1', false); } catch (e) {}

        start();

        window.clearInterval(ticker);
        ticker = window.setInterval(tick, 1000);
        tick();

        root.addEventListener('ajaxlist:loaded', afterLoad);

        /*
         | A screen nobody is looking at does not need refreshing, and a tab
         | left open all night should not be a request every seven seconds
         | until morning. Coming back refreshes at once rather than waiting
         | out the interval, because the first thing anybody does on
         | returning is look at the board.
         */
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { return; }
            poll();
            tick();
        });

        listenLive();
    }

    /*
     | Subscribe to this branch's board, if broadcasting is switched on.
     |
     | Everything here is optional by construction: no window.Realtime (the
     | default), no shop id on the element, or a socket that never connects,
     | and the screen carries on polling exactly as it always has.
     */
    function listenLive() {
        var shopId = root.getAttribute('data-kds-shop');

        if (!shopId || !window.Realtime) { return; }

        window.Realtime.listen('shop.' + shopId, function () {
            // Straight to the board rather than waiting out the heartbeat.
            // This is the whole point of the socket.
            poll();
        });

        // Re-pace the poll whenever the socket comes up or goes down.
        document.addEventListener('realtime:state', start);
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-kds-sound]');

        if (toggle) {
            event.preventDefault();
            setSound(!sound, true);
            return;
        }

        var full = event.target.closest('[data-kds-fullscreen]');

        if (full) {
            event.preventDefault();

            var target = document.querySelector('[data-kds]');
            if (!target) { return; }

            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (target.requestFullscreen) {
                target.requestFullscreen().catch(function () {
                    if (window.Toast) {
                        window.Toast.error('This browser would not go full screen.');
                    }
                });
            }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
