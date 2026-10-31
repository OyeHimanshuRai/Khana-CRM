/*
|------------------------------------------------------------------------------
| The guest's order, live (§15)
|------------------------------------------------------------------------------
|
| On "Your orders": every round and every dish shows where it is - received,
| being prepared, ready, served - and that changes as the kitchen bumps it,
| without the guest pulling to refresh.
|
| Progressive, like everything else on the guest's side (docs/ERP-OVERVIEW
| 15.6). Without this file the page is exactly what it was: correct when it
| loaded, and right again on a reload. With it, the same page keeps itself
| current. Nothing a guest can do depends on it.
|
|   - asks TableOrderController@live every 8 seconds while the page is visible
|   - rewrites the stage labels in place
|   - when a dish turns Ready: a banner, a buzz and a chime
|   - when a round appears that the page does not have (a waiter added one),
|     reloads, because the page has no markup for it
|   - when the sitting has ended, goes where the server says
*/
(function (window, document) {
    'use strict';

    var root = document.querySelector('[data-live-orders]');

    if (!root || !window.fetch) {
        return;
    }

    var INTERVAL = 8000;
    var STORE = 'erp.table.sound';
    var url = root.getAttribute('data-live-url');
    var timer = null;
    var busy = false;

    /*
     | The guest's own switch, on this phone. On unless they turned it off:
     | the whole point is to hear "your food is ready" with the phone face
     | down on the table. Browsers still need one tap on the page first,
     | which the button itself provides.
     */
    var soundOn = true;
    try { soundOn = window.localStorage.getItem(STORE) !== '0'; } catch (e) { /* private mode */ }

    var toggle = document.querySelector('[data-live-sound]');

    function paintToggle() {
        if (!toggle) { return; }
        toggle.hidden = false;
        toggle.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        toggle.textContent = soundOn ? '🔔 Sound on' : '🔕 Sound off';
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            soundOn = !soundOn;
            try { window.localStorage.setItem(STORE, soundOn ? '1' : '0'); } catch (e) {}
            paintToggle();
            if (soundOn) { chime(); }
        });
        paintToggle();
    }

    // Any tap unlocks audio and speech for the rest of the visit.
    document.addEventListener('pointerdown', function unlock() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                chime.ctx = chime.ctx || new Ctx();
                if (chime.ctx.state === 'suspended') { chime.ctx.resume(); }
            }
            if (window.speechSynthesis) {
                window.speechSynthesis.speak(new window.SpeechSynthesisUtterance(''));
            }
        } catch (e) {}
        document.removeEventListener('pointerdown', unlock, true);
    }, true);

    function say(text) {
        if (!soundOn || !window.speechSynthesis || !window.SpeechSynthesisUtterance) { return; }

        try {
            var utterance = new window.SpeechSynthesisUtterance(text);
            utterance.lang = document.documentElement.lang === 'hi' ? 'hi-IN' : 'en-IN';
            window.speechSynthesis.speak(utterance);
        } catch (e) {}
    }

    /** id => last status seen, so only a *change* to ready rings. */
    var seen = {};

    Array.prototype.forEach.call(root.querySelectorAll('[data-line-status]'), function (node) {
        seen['line:' + node.getAttribute('data-line-status')] = node.getAttribute('data-status');
    });

    Array.prototype.forEach.call(root.querySelectorAll('[data-order-status]'), function (node) {
        seen['order:' + node.getAttribute('data-order-status')] = node.getAttribute('data-status');
    });

    function paint(node, status, label) {
        if (!node || node.getAttribute('data-status') === status) {
            return false;
        }

        node.className = node.className.replace(/\bis-[a-z_]+\b/g, '').trim() + ' is-' + status;
        node.setAttribute('data-status', status);
        node.textContent = label;

        // A brief highlight, so the eye catches which line moved.
        node.classList.add('is-changed');
        window.setTimeout(function () { node.classList.remove('is-changed'); }, 1600);

        return true;
    }

    function chime() {
        if (!soundOn) { return; }

        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) { return; }
            var ctx = chime.ctx || (chime.ctx = new Ctx());
            var now = ctx.currentTime;
            [660, 990].forEach(function (freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + i * 0.16);
                gain.gain.exponentialRampToValueAtTime(0.2, now + i * 0.16 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.16 + 0.4);
                osc.connect(gain).connect(ctx.destination);
                osc.start(now + i * 0.16);
                osc.stop(now + i * 0.16 + 0.45);
            });
        } catch (e) { /* silence is fine */ }
    }

    function banner(text) {
        var box = document.querySelector('[data-live-banner]');

        if (!box) {
            box = document.createElement('div');
            box.className = 't-live-banner';
            box.setAttribute('data-live-banner', '');
            box.setAttribute('role', 'status');
            root.insertBefore(box, root.firstChild);
        }

        box.textContent = text;
        box.hidden = false;

        window.clearTimeout(banner.timer);
        banner.timer = window.setTimeout(function () { box.hidden = true; }, 12000);
    }

    function apply(data) {
        var known = root.querySelectorAll('[data-order-status]').length;

        if ((data.orders || []).length !== known) {
            // A round this page has no card for. Reloading is simpler and
            // more honest than building one here.
            window.location.reload();
            return;
        }

        var ready = [];

        data.orders.forEach(function (order) {
            var orderNode = root.querySelector('[data-order-status="' + order.id + '"]');

            if (paint(orderNode, order.status, order.label)) {
                seen['order:' + order.id] = order.status;
            }

            (order.lines || []).forEach(function (line) {
                var node = root.querySelector('[data-line-status="' + line.id + '"]');
                var before = seen['line:' + line.id];

                if (paint(node, line.status, line.label)) {
                    if (line.status === 'ready' && before !== 'ready') {
                        ready.push(line.name);
                    }
                    seen['line:' + line.id] = line.status;
                }
            });
        });

        if (ready.length) {
            banner(ready.length === 1
                ? ready[0] + ' is ready!'
                : ready.length + ' dishes are ready!');

            if (navigator.vibrate) {
                navigator.vibrate([180, 80, 180]);
            }

            chime();

            // After the chime: "Your Paneer Tikka is ready".
            window.setTimeout(function () {
                say(ready.length === 1
                    ? 'Your ' + ready[0] + ' is ready'
                    : 'Your food is ready');
            }, 500);
        }
    }

    function check() {
        if (busy || document.hidden) {
            return;
        }

        busy = true;

        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { status: response.status, payload: payload };
                });
            })
            .then(function (result) {
                var payload = result.payload || {};

                // The sitting is over (bill paid, table cleared): go where
                // the server says rather than polling a page that is dead.
                if (result.status === 410 && payload.redirect) {
                    stop();
                    window.location.assign(payload.redirect);
                    return;
                }

                if (payload.success && payload.data) {
                    apply(payload.data);
                }
            })
            .catch(function () { /* next tick */ })
            .then(function () { busy = false; });
    }

    function start() {
        if (!timer) { timer = window.setInterval(check, INTERVAL); }
    }

    function stop() {
        window.clearInterval(timer);
        timer = null;
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            check();
            start();
        }
    });

    start();
})(window, document);
