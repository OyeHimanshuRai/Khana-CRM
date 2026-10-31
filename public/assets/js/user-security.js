/*
|------------------------------------------------------------------------------
| User security screen
|------------------------------------------------------------------------------
|
| Two behaviours, both delegated so they work inside the list's modal as
| well as on the standalone page:
|
|   [data-ajax-reload]   a data-ajax form whose success invalidates what is
|                        on screen - reload the fragment or the page
|   [data-ip-add="ip"]   append an address to the block form's textarea
|
| Everything else on the screen is already covered: app.js posts the forms,
| tabs.js drives the panels, modal.js loads the fragment.
|
| Depends on: public/assets/js/app.js, modal.js
*/
(function (window, document) {
    'use strict';

    /* ------------------------------------------------- reload on success */

    /*
     | Ending a session or blocking an address changes rows all over the
     | fragment - counts, badges, the tab labels. Re-fetching is both simpler
     | and more honest than patching each one, and the modal already knows
     | how to load this markup.
     */
    document.addEventListener('ajax:success', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-ajax-reload]')) { return; }

        var modal = form.closest('[data-modal-root]');

        // Give the toast a moment to be read before the ground moves.
        window.setTimeout(function () {
            if (modal && window.Modal && window.Modal.reload) {
                window.Modal.reload();
                return;
            }

            window.location.reload();
        }, 600);
    });

    /* ----------------------------------------------------- ip shortlist */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ip-add]');
        if (!button) { return; }

        event.preventDefault();

        var ip = button.getAttribute('data-ip-add');
        // Scoped to this fragment, so the page and the modal do not cross.
        var scope = button.closest('[data-tab-panel]') || document;
        var target = scope.querySelector('[data-ip-target]');
        if (!ip || !target) { return; }

        var current = target.value
            .split(/[\s,]+/)
            .map(function (value) { return value.trim(); })
            .filter(Boolean);

        if (current.indexOf(ip) === -1) {
            current.push(ip);
        }

        target.value = current.join(', ');
        target.focus();
        // Caret to the end, so typing continues the list rather than
        // overwriting it.
        target.setSelectionRange(target.value.length, target.value.length);
    });
})(window, document);
