/*
|------------------------------------------------------------------------------
| Settings > General
|------------------------------------------------------------------------------
|
| Image pickers on the company settings screen:
|
|   [data-setting-file="key"]   wrapper for one image field
|     [data-file-preview]       box holding the <img>, or the "No image" note
|     [data-file-input]         the <input type="file">
|     [data-file-remove]        clears the stored file, via data-remove-url
|
| Saving itself is handled by app.js (the section forms carry data-ajax);
| this file only keeps the previews honest, since the page is never
| re-rendered after a save.
|
| Depends on: public/assets/js/app.js (Toast), theme.css (.setting-image)
*/
(function (window, document) {
    'use strict';

    function fieldBox(el) {
        return el.closest('[data-setting-file]');
    }

    /** Paint a preview box with an image, or the empty-state note. */
    function paint(box, url) {
        var preview = box.querySelector('[data-file-preview]');
        var remove = box.querySelector('[data-file-remove]');

        if (!preview) { return; }

        if (url) {
            var img = preview.querySelector('img');
            if (!img) {
                preview.textContent = '';
                img = document.createElement('img');
                img.alt = '';
                preview.appendChild(img);
            }
            img.src = url;
        } else {
            preview.innerHTML = '<span class="text-xs text-muted">No image</span>';
        }

        if (remove) { remove.hidden = !url; }
    }

    /* --------------------------------------------------- local preview */

    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-file-input]');
        if (!input) { return; }

        var box = fieldBox(input);
        var file = input.files && input.files[0];
        if (!box || !file) { return; }

        var url = window.URL.createObjectURL(file);
        paint(box, url);

        var img = box.querySelector('[data-file-preview] img');
        if (img) {
            img.addEventListener('load', function () {
                window.URL.revokeObjectURL(url);
            }, { once: true });
        }
    });

    /* ---------------------------------------------------------- remove */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-file-remove]');
        if (!button) { return; }

        event.preventDefault();

        var box = fieldBox(button);
        var url = button.getAttribute('data-remove-url');
        if (!box || !url) { return; }

        if (!window.confirm('Remove this image?')) { return; }

        var meta = document.querySelector('meta[name="csrf-token"]');

        button.disabled = true;

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
            },
            // Spoofed rather than a real DELETE, matching the app's forms.
            body: new URLSearchParams({ _method: 'DELETE' })
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            button.disabled = false;

            if (!payload || !payload.success) {
                if (window.Toast) {
                    window.Toast.error((payload && payload.message) || 'Could not remove that image.');
                }
                return;
            }

            paint(box, null);

            // The picker may still hold a file the user chose but never sent.
            var input = box.querySelector('[data-file-input]');
            if (input) { input.value = ''; }

            if (window.Toast) { window.Toast.success(payload.message); }
        }).catch(function () {
            button.disabled = false;
            if (window.Toast) {
                window.Toast.error('Could not reach the server. Please try again.');
            }
        });
    });

    /* ----------------------------------------------- refresh on save */

    /*
     | The server answers a section save with the current URL of every file
     | in that section, so an upload settles on the stored copy rather than
     | leaving the local object URL on screen.
     */
    document.addEventListener('ajax:success', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-settings-section]')) { return; }

        var files = event.detail && event.detail.data && event.detail.data.files;
        if (!files) { return; }

        Object.keys(files).forEach(function (key) {
            var box = form.querySelector('[data-setting-file="' + key + '"]');
            if (!box) { return; }

            paint(box, files[key]);

            // A sent file must not be re-sent on the next save of this section.
            var input = box.querySelector('[data-file-input]');
            if (input) { input.value = ''; }
        });
    });
})(window, document);
