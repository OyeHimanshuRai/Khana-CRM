/*
|------------------------------------------------------------------------------
| Showing a new API token, once (§2, §21)
|------------------------------------------------------------------------------
|
| Sanctum stores a hash. The plain token exists for exactly one response and is
| then unrecoverable — which is correct, and is why this file exists: the value
| has to be put in front of somebody at the moment it is created, because there
| is no second chance and there must not be a "show token" button.
|
| The form posts through app.js like every other, so all this does is catch the
| response and reveal the box.
*/
(function (window, document) {
    'use strict';

    document.addEventListener('ajax:success', function (event) {
        var form = event.target;

        if (!form || !form.matches || !form.matches('[data-token-form]')) { return; }

        var payload = event.detail && event.detail.data ? event.detail.data : null;

        if (!payload || !payload.token) { return; }

        var box = form.parentElement.querySelector('[data-token-result]');
        var value = form.parentElement.querySelector('[data-token-value]');

        if (!box || !value) { return; }

        value.textContent = payload.token;
        box.hidden = false;

        /*
         | Selected, not copied. Writing to the clipboard without being asked
         | is a thing browsers increasingly refuse and people reasonably
         | dislike; selecting it means one keystroke and no permission prompt.
         */
        try {
            var range = document.createRange();
            range.selectNodeContents(value);

            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        } catch (e) { /* selection is a convenience, never a requirement */ }

        form.reset();
    });
})(window, document);
