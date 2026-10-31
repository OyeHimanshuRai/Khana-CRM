/*
|------------------------------------------------------------------------------
| Notification sound and voice, for the whole admin panel
|------------------------------------------------------------------------------
|
| One place that decides whether something makes a noise, and what noise:
|
|   NotifySound.announce(type, { en: 'New order, Table 4', hi: '...' })
|
|   - checks the person's own settings (Profile > Notifications, printed on
|     the page as window.__notifyPrefs): sound on at all, and on for this
|     kind of event
|   - plays a short chime, different for orders, the kitchen, money and
|     warnings, so the room knows which without looking
|   - then says it out loud, in the chosen language, if voice is on
|
| Used by alerts-live.js (the bell) and kds.js (the kitchen board).
|
| ---------------------------------------------------------------------------
| Browsers will not make a sound until somebody has clicked
| ---------------------------------------------------------------------------
|
| Every browser blocks audio and speech on a page nobody has interacted with.
| So the first click or key press anywhere unlocks both, and anything that
| arrives before that shows one quiet hint instead of failing silently -
| "click anywhere to hear notifications" - once per page.
|
| No sound files: WebAudio tones and the browser's own speech, so there is
| nothing to download, cache or license.
*/
(function (window, document) {
    'use strict';

    var DEFAULTS = { sound: true, voice: true, voice_lang: 'en-IN', volume: 80, types: {} };

    var prefs = Object.assign({}, DEFAULTS, window.__notifyPrefs || {});
    var ctx = null;
    var unlocked = false;
    var hinted = false;

    /* ------------------------------------------------------------ settings */

    function wants(type, channel) {
        if (channel === 'sound' && !prefs.sound) { return false; }

        var set = prefs.types && prefs.types[type];

        return !set || set[channel] !== false;
    }

    function volume() {
        return Math.max(0, Math.min(100, parseInt(prefs.volume, 10) || 0)) / 100;
    }

    /* -------------------------------------------------------------- unlock */

    function unlock() {
        if (unlocked) { return; }
        unlocked = true;

        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                ctx = ctx || new Ctx();
                if (ctx.state === 'suspended') { ctx.resume(); }
            }
        } catch (e) { /* no audio output */ }

        // An empty utterance inside the gesture is what lets later ones play.
        try {
            if (window.speechSynthesis) {
                window.speechSynthesis.speak(new window.SpeechSynthesisUtterance(''));
            }
        } catch (e) { /* no speech */ }
    }

    ['pointerdown', 'keydown', 'touchstart'].forEach(function (name) {
        document.addEventListener(name, unlock, { capture: true, passive: true });
    });

    function hint() {
        if (hinted || !window.Toast) { return; }
        hinted = true;

        window.Toast.info('Click anywhere on the page to hear notification sounds.', {
            title: 'Sound is waiting',
            duration: 6000
        });
    }

    /* --------------------------------------------------------------- tones */

    /*
     | A family of tones per kind of event, so a busy room can tell the
     | kitchen's double beep from the counter's order chime without looking:
     |   order    three rising notes          kitchen   two firm beeps
     |   money    two bright rising notes     warning   two falling notes
     */
    var TONES = {
        order: [[660, 0], [880, 0.13], [1100, 0.26]],
        kitchen: [[880, 0], [880, 0.22]],
        money: [[784, 0], [1175, 0.15]],
        warning: [[660, 0], [440, 0.2]],
        info: [[880, 0], [1320, 0.14]]
    };

    function family(type) {
        switch (type) {
            case 'order_placed': return 'order';
            case 'kitchen_ticket': return 'kitchen';
            case 'payment_received':
            case 'subscription_paid':
            case 'signup': return 'money';
            case 'low_stock':
            case 'security':
            case 'table_stuck':
            case 'day_close_pending': return 'warning';
            default: return 'info';
        }
    }

    function chime(kind) {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) { return; }

            ctx = ctx || new Ctx();
            if (ctx.state === 'suspended') { ctx.resume(); }

            var peak = 0.05 + 0.3 * volume();
            var now = ctx.currentTime;

            (TONES[kind] || TONES.info).forEach(function (note) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                var at = now + note[1];

                osc.type = kind === 'kitchen' ? 'square' : 'sine';
                osc.frequency.value = note[0];
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(peak, at + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.3);
                osc.connect(gain).connect(ctx.destination);
                osc.start(at);
                osc.stop(at + 0.32);
            });
        } catch (e) { /* silence is fine */ }
    }

    /* --------------------------------------------------------------- voice */

    function voiceFor(lang) {
        var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
        var base = lang.split('-')[0];

        return voices.filter(function (v) { return v.lang === lang; })[0]
            || voices.filter(function (v) { return v.lang && v.lang.indexOf(base) === 0; })[0]
            || null;
    }

    function speak(text) {
        if (!text || !window.speechSynthesis || !window.SpeechSynthesisUtterance) { return; }

        try {
            var utterance = new window.SpeechSynthesisUtterance(text);
            var voice = voiceFor(prefs.voice_lang || 'en-IN');

            utterance.lang = voice ? voice.lang : (prefs.voice_lang || 'en-IN');
            if (voice) { utterance.voice = voice; }
            utterance.volume = volume();
            utterance.rate = 1;

            // Queued, not cancelled: three orders in a row are three
            // announcements, read one after another.
            window.speechSynthesis.speak(utterance);
        } catch (e) { /* no speech */ }
    }

    /** Pick the sentence in the chosen language, falling back to English. */
    function sentence(spoken) {
        if (!spoken) { return ''; }
        if (typeof spoken === 'string') { return spoken; }

        var lang = (prefs.voice_lang || 'en-IN').split('-')[0];

        return spoken[lang] || spoken.en || '';
    }

    /* ---------------------------------------------------------------- api */

    /**
     * Make the noise for one event, if this person wants it.
     *
     * @param {string} type    an alert type, or 'kitchen_ticket'
     * @param {object} spoken  { en: '...', hi: '...' } or a plain string
     * @return {boolean} whether anything was played
     */
    function announce(type, spoken) {
        if (!wants(type, 'sound')) { return false; }

        if (!unlocked) {
            hint();
            return false;
        }

        chime(family(type));

        if (prefs.voice) {
            // After the chime, so the two do not talk over each other.
            window.setTimeout(function () { speak(sentence(spoken)); }, 450);
        }

        return true;
    }

    window.NotifySound = {
        announce: announce,
        wants: wants,
        chime: function (type) { chime(family(type)); },
        speak: speak,
        prefs: function () { return prefs; },
        set: function (next) { prefs = Object.assign({}, prefs, next || {}); }
    };

    /* ------------------------------------------- the settings screen itself */

    // "Test sound" plays with what is on the form now, saved or not.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-notify-test]');
        if (!button) { return; }

        unlock();

        var form = button.closest('form');
        var saved = prefs;

        if (form) {
            var read = function (name) { return form.querySelector('[data-notify-pref="' + name + '"]'); };
            prefs = Object.assign({}, prefs, {
                sound: read('sound') ? read('sound').checked : prefs.sound,
                voice: read('voice') ? read('voice').checked : prefs.voice,
                voice_lang: read('voice_lang') ? read('voice_lang').value : prefs.voice_lang,
                volume: read('volume') ? read('volume').value : prefs.volume,
                types: {}
            });
        }

        if (!prefs.sound) {
            if (window.Toast) { window.Toast.info('Sound is switched off above.'); }
        } else {
            announce('order_placed', { en: 'New order, Table 4', hi: 'नया ऑर्डर, टेबल 4' });
        }

        // Put the saved settings back once the voice has picked these up -
        // the sentence is spoken a moment after the chime.
        window.setTimeout(function () { prefs = saved; }, 1500);
    });

    // Saving the screen takes effect on this page straight away.
    document.addEventListener('ajax:success', function (event) {
        if (!event.target.matches('[data-notify-form]')) { return; }

        var next = event.detail && event.detail.data && event.detail.data.prefs;
        if (next) { prefs = Object.assign({}, prefs, next); }
    });

    // Voices load asynchronously in Chrome; asking once warms the list.
    if (window.speechSynthesis) {
        window.speechSynthesis.getVoices();
    }
})(window, document);
