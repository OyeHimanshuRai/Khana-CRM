/*
|------------------------------------------------------------------------------
| The audience count on the campaign form (§15)
|------------------------------------------------------------------------------
|
| The most important control on that form, and the reason this file exists.
| A campaign sent to the wrong list cannot be recalled, and "214 people" beside
| the rules is the only thing that catches it in time — a confirmation step
| would not, because nobody reads those.
|
| Registered globally rather than inside the form, because the form is injected
| into a modal via innerHTML and a <script> that arrives that way never runs.
|
| Everything here is optional: if the request fails, the box keeps whatever it
| last said and the form still saves. The server resolves the audience again at
| send time anyway, so this is a preview and never the source of truth.
*/
(function (window, document) {
    'use strict';

    var DEBOUNCE = 350;

    var timer = null;

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function form(el) {
        return el ? el.closest('[data-campaign-form]') : null;
    }

    function refresh(campaignForm) {
        var box = campaignForm.querySelector('[data-audience]');
        var url = campaignForm.getAttribute('data-preview-url');

        if (!box || !url) { return; }

        var body = new FormData();

        // Only the segment fields. Sending the whole form would post the
        // message body on every keystroke for no reason.
        campaignForm.querySelectorAll('[data-segment]').forEach(function (field) {
            if (field.type === 'checkbox') {
                if (field.checked) { body.append(field.name, '1'); }
                return;
            }

            if (field.value !== '') { body.append(field.name, field.value); }
        });

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() },
            body: body
        }).then(function (response) {
            if (!response.ok) { throw new Error('preview failed'); }

            return response.json();
        }).then(function (payload) {
            var count = payload.data ? payload.data.count : 0;

            box.textContent = payload.data ? payload.data.label : '';

            // Nobody matching is the one case worth colouring: it means the
            // campaign would go out to an empty list.
            box.classList.toggle('alert-warning', count === 0);
            box.classList.toggle('alert-info', count !== 0);
        }).catch(function () {
            /*
             | Left as it was. The count is a preview; the server works the
             | audience out again at send time, so a failed preview must not
             | stop somebody saving a draft.
             */
        });
    }

    function schedule(campaignForm) {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { refresh(campaignForm); }, DEBOUNCE);
    }

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-segment]')) { return; }

        var campaignForm = form(event.target);

        if (campaignForm) { schedule(campaignForm); }
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-segment]')) { return; }

        var campaignForm = form(event.target);

        if (campaignForm) { schedule(campaignForm); }
    });

    /*
     | First count when the modal opens. The form arrives after page load, so
     | this watches for it rather than running once on DOMContentLoaded.
     */
    new MutationObserver(function (records) {
        records.forEach(function (record) {
            Array.prototype.forEach.call(record.addedNodes, function (node) {
                if (node.nodeType !== 1) { return; }

                var campaignForm = node.matches && node.matches('[data-campaign-form]')
                    ? node
                    : (node.querySelector ? node.querySelector('[data-campaign-form]') : null);

                if (campaignForm) { refresh(campaignForm); }
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
})(window, document);
