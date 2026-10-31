/*
|------------------------------------------------------------------------------
| The bell, live (SRS 15)
|------------------------------------------------------------------------------
|
| The count on the bell is server-rendered, so it is right when the page
| loads and stale a minute later. This keeps it right without a reload:
|
|   - asks AlertController@feed for anything newer than the newest alert the
|     page already knows about, every 15 seconds while the tab is visible
|   - updates the number on the bell and the (n) in the tab title
|   - shows each new alert as a toast, and has notify-sound.js chime and
|     say it ("New order, Table 4") - each kind obeying the person's own
|     switches on Profile > Notifications
|   - marks the dropdown stale, so the next open fetches the new rows
|
| A Firebase message arriving while the tab is open (firebase-push.js) asks
| for an immediate check instead of waiting for the next tick.
|
| Polling rather than a socket for the same reason the kitchen board polls
| (docs/ERP-OVERVIEW 16.3): a poll that fails simply tries again, and the
| worst case is fifteen seconds. Reverb, when it is configured, is a nudge on
| top - see realtime.js - never a replacement.
|
| Depends on: app.js (Toast), notify-sound.js (NotifySound)
*/
(function (window, document) {
    'use strict';

    var bell = document.querySelector('[data-alert-bell]');

    if (!bell || !window.fetch) {
        return;
    }

    var INTERVAL = 15000;
    var MAX_TOASTS = 3;

    var feedUrl = bell.getAttribute('data-alert-feed');
    var latest = parseInt(bell.getAttribute('data-alert-latest'), 10) || 0;
    var badge = bell.querySelector('[data-alert-count]');
    var button = bell.querySelector('[data-dropdown-toggle]');
    var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
    var timer = null;
    var busy = false;

    /* ------------------------------------------------------------ display */

    function setCount(count) {
        count = Math.max(0, count | 0);

        if (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count === 0;
        }

        if (button) {
            button.setAttribute('aria-label', count > 0 ? 'Notifications (' + count + ' unread)' : 'Notifications');
        }

        document.title = count > 0 ? '(' + count + ') ' + baseTitle : baseTitle;
    }

    /*
     | The noise is notify-sound.js's: one chime for the batch (the kind of
     | the first), then each announcement read out in turn - "New order,
     | Table 4", "New order, Table 7" - in the language this person chose.
     | Each kind obeys its own switch on Profile > Notifications.
     */
    function sound(items) {
        if (!window.NotifySound) { return; }

        var audible = items.filter(function (item) {
            return window.NotifySound.wants(item.type, 'sound');
        });

        if (!audible.length) { return; }

        if (window.NotifySound.announce(audible[0].type, audible[0].spoken)) {
            audible.slice(1, MAX_TOASTS).forEach(function (item, i) {
                window.setTimeout(function () {
                    var lang = (window.NotifySound.prefs().voice_lang || 'en').split('-')[0];
                    if (window.NotifySound.prefs().voice) {
                        window.NotifySound.speak((item.spoken && (item.spoken[lang] || item.spoken.en)) || item.title);
                    }
                }, 600 * (i + 1));
            });
        }
    }

    function announce(items) {
        if (!items.length) {
            return;
        }

        // The dropdown was fetched once; make the next open fetch again.
        // For every new alert, quiet ones included - they are still listed.
        bell.dataset.fragmentLoaded = '0';

        // Sound has its own switch per kind, independent of the toast.
        sound(items);

        // Kinds this person switched off "show on screen" are listed, not toasted.
        items = items.filter(function (item) { return item.toast !== false; });

        if (!items.length || !window.Toast) {
            return;
        }

        var shown = items.slice(0, MAX_TOASTS);

        shown.forEach(function (item) {
            var kind = item.level === 'danger' ? 'error' : (item.level === 'warning' ? 'warning' : 'info');
            var toast = window.Toast.show(kind, item.body || item.shop || '', {
                title: item.title,
                duration: 8000
            });

            if (toast && item.link) {
                toast.style.cursor = 'pointer';
                toast.addEventListener('click', function (event) {
                    if (!event.target.closest('.toast-close')) {
                        window.location.assign(item.link);
                    }
                });
            }
        });

        if (items.length > MAX_TOASTS) {
            window.Toast.info((items.length - MAX_TOASTS) + ' more - open the bell to see them all.', {
                title: 'New notifications'
            });
        }
    }

    /* --------------------------------------------------------------- poll */

    function check() {
        if (busy || document.hidden) {
            return;
        }

        busy = true;

        window.fetch(feedUrl + (feedUrl.indexOf('?') === -1 ? '?' : '&') + 'after=' + latest, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                // Signed out, or the account was paused: stop asking.
                if (response.status === 401 || response.status === 403 || response.status === 419) {
                    stop();
                    return null;
                }
                return response.ok ? response.json() : null;
            })
            .then(function (payload) {
                if (!payload || !payload.data) { return; }

                var data = payload.data;

                setCount(data.unread);

                if (data.latest_id > latest) {
                    latest = data.latest_id;
                }

                announce(data.items || []);
            })
            .catch(function () { /* the next tick tries again */ })
            .then(function () { busy = false; });
    }

    function start() {
        if (!timer) {
            timer = window.setInterval(check, INTERVAL);
        }
    }

    function stop() {
        window.clearInterval(timer);
        timer = null;
    }

    // Paused while the tab is hidden; caught up the moment it is back.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            check();
            start();
        }
    });

    // firebase-push.js, or anything else, can ask for a check now.
    window.addEventListener('erp:alert', check);

    // Reading one in the dropdown lowers the count without waiting a tick.
    document.addEventListener('ajax:success', function (event) {
        var detail = event.detail || {};
        if (detail.data && typeof detail.data.unread === 'number') {
            setCount(detail.data.unread);
        }
    });

    window.AlertsLive = { check: check, setCount: setCount };

    setCount(parseInt(badge ? badge.textContent : '0', 10) || 0);
    start();
})(window, document);
