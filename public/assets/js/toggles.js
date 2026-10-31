/*
 * On/off switches in a listing: answer at once, and let the person take it back.
 *
 * Two behaviours, each only where it fits.
 *
 *  Optimistic. A switch that reads "Active" / "Inactive" is a coin: the outcome
 *  of pressing it is known before the server says so. So the pill flips the
 *  moment it is pressed, and the server's answer confirms it - or, if the
 *  request fails, the pill flips back and says why. The listing then refreshes
 *  as it always did, so what is on screen is always what is stored.
 *
 *  This is done only when the pill carries a word with a clear opposite (Active,
 *  Listed, Enabled ...). A control whose result is not obvious from its label is
 *  left alone: showing a guess that turns out wrong is worse than waiting.
 *
 *  Undo. Pressing a switch by mistake in a row of two hundred is the most
 *  ordinary mistake there is, and flipping it back is not a destructive act - so
 *  the confirmation toast carries "Undo", for a few seconds longer than usual.
 *  It sends the same toggle again (these are flips) and refreshes the list.
 *
 * Which forms. Any ajax form in a listing that holds a .status-toggle, plus any
 * form that asks with data-undo. Nothing else gets either: an invoice is not
 * "undone" with a toast, and a form that creates something has its own screen.
 */
(function (window, document) {
    'use strict';

    var UNDO_FOR = 8000;

    /* Opposites that are safe to show ahead of the server. Matched whole-word, any case. */
    var PAIRS = [
        ['Active', 'Inactive'], ['Listed', 'Hidden'], ['Published', 'Unpublished'],
        ['Enabled', 'Disabled'], ['Open', 'Closed'], ['Visible', 'Hidden'],
        ['Live', 'Paused'], ['Featured', 'Standard']
    ];

    function opposite(word) {
        var w = word.trim().toLowerCase();

        for (var i = 0; i < PAIRS.length; i++) {
            if (PAIRS[i][0].toLowerCase() === w) { return PAIRS[i][1]; }
            if (PAIRS[i][1].toLowerCase() === w) { return PAIRS[i][0]; }
        }

        return null;
    }

    function wantsUndo(form) {
        return form.hasAttribute('data-undo') || !!form.querySelector('.status-toggle');
    }

    function switchOf(form) {
        return form.querySelector('.status-toggle') || (form.hasAttribute('data-optimistic-class') ? form.querySelector('button') : null);
    }

    /*
     * The text node holding the label ("Active"), so the dot and any icon beside
     * it survive. Searched through the whole button, not just its children: by
     * the time this runs app.js has wrapped the label in a .btn-label span.
     */
    function labelNode(button) {
        var walker = document.createTreeWalker(button, NodeFilter.SHOW_TEXT, null);
        var node;

        while ((node = walker.nextNode())) {
            if (node.textContent.trim() !== '') { return node; }
        }

        return null;
    }

    /* The busy spinner belongs to the request, not to the state being restored. */
    function settled(className) {
        return className.replace(/\bis-busy\b/g, '').replace(/\s+/g, ' ').trim();
    }

    /* ---------------------------------------------------------------- before */

    document.addEventListener('ajax:before', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('form[data-ajax]') || !wantsUndo(form)) { return; }

        // Remember what was asked, so it can be asked again to undo it.
        form._undo = { action: form.getAttribute('action'), data: new FormData(form) };

        // The toast is ours now (it carries the Undo button); app.js stays quiet.
        if (!form.hasAttribute('data-toast-success')) { form.setAttribute('data-toast-success', 'false'); form._tookToast = true; }

        var control = switchOf(form);
        if (!control) { return; }

        form._before = { html: control.innerHTML, cls: settled(control.className), title: control.getAttribute('title') };

        var cls = form.getAttribute('data-optimistic-class');
        if (cls) {
            control.classList.toggle(cls);
            return;
        }

        var node = labelNode(control);
        var flipped = node ? opposite(node.textContent) : null;

        if (flipped) {
            node.textContent = ' ' + flipped + ' ';
            control.classList.toggle('is-on');
            control.removeAttribute('title');
        } else {
            form._before = null;   // not a word we can safely turn over: wait for the answer
        }
    });

    /* ------------------------------------------------------------------ after */

    function reload(form) {
        if (!window.AjaxList) { return; }
        var selector = form.getAttribute('data-refresh-list');
        var list = selector ? document.querySelector(selector) : document.querySelector('[data-ajax-list]');
        if (list) { window.AjaxList.reload(list); }
    }

    document.addEventListener('ajax:error', function (event) {
        var form = event.target;
        if (!form || !form._before) { return; }

        // It did not happen: put the switch back as it was.
        var control = switchOf(form);
        if (control) {
            control.innerHTML = form._before.html;
            control.className = form._before.cls;
            if (form._before.title) { control.setAttribute('title', form._before.title); }
        }

        form._before = null;
    });

    document.addEventListener('ajax:success', function (event) {
        var form = event.target;
        if (!form || !form._undo || !window.Toast) { return; }

        var undo = form._undo;
        var message = (event.detail && event.detail.message) || 'Done.';
        form._undo = null;
        form._before = null;

        window.Toast.success(message, {
            duration: UNDO_FOR,
            action: {
                label: 'Undo',
                onClick: function () {
                    var token = document.querySelector('meta[name="csrf-token"]');

                    window.fetch(undo.action, {
                        method: 'POST',
                        body: undo.data,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
                        }
                    }).then(function (response) {
                        return response.json().catch(function () { return {}; }).then(function (json) {
                            if (!response.ok || json.success === false) {
                                throw new Error(json.message || 'Could not undo that.');
                            }
                            window.Toast.info('Undone.', { duration: 2500 });
                            reload(form);
                        });
                    }).catch(function (error) {
                        window.Toast.error(error.message || 'Could not undo that.');
                    });
                }
            }
        });
    });
})(window, document);
