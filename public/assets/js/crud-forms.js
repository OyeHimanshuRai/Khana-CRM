/*
|------------------------------------------------------------------------------
| Modal CRUD forms
|------------------------------------------------------------------------------
|
| Shared behaviour for every add/edit form that opens in a modal - Categories
| and Services today, whatever comes next tomorrow. All delegated, because
| the form is injected by modal.js and a <script> inside it would never run.
|
|   [data-image-field]   wrapper for one image, holding:
|     [data-file-preview]  the box the <img> lives in
|     [data-file-input]    the picker; previews the choice before sending
|     [data-image-remove]  deletes the stored file via data-remove-url
|
|   [data-slug-source]   type a name, watch [data-slug-target] fill in
|
| Submitting, toasts, inline field errors, closing the modal and refreshing
| the listing are all app.js - see data-close-modal / data-refresh-list.
|
| Depends on: public/assets/js/app.js, modal.js
*/
(function (window, document) {
    'use strict';

    /* Both selectors: the single-image fields predate the media widget. */
    var FIELD = '[data-image-field], [data-media-field]';

    function imageBox(el) {
        return el.closest(FIELD);
    }

    /** image or video - decides what element the preview needs. */
    function kindOf(box) {
        return box.getAttribute('data-media-kind') === 'video' ? 'video' : 'image';
    }

    /** Paint the preview with the media, or clear it back to the empty state. */
    function paint(box, url) {
        var preview = box.querySelector('[data-file-preview]');
        if (!preview) { return; }

        var remove = box.querySelector('[data-image-remove], [data-media-remove]');
        var zone = box.querySelector('[data-dropzone]');
        var pick = box.querySelector('[data-media-pick]');

        if (!url) {
            // The media widget has its own empty state markup underneath;
            // the older single-image field just gets a note.
            preview.innerHTML = zone ? '' : '<span class="text-xs text-muted">No image</span>';
        } else if (kindOf(box) === 'video') {
            var video = preview.querySelector('video');
            if (!video) {
                preview.textContent = '';
                video = document.createElement('video');
                video.controls = true;
                video.preload = 'metadata';
                video.playsInline = true;
                preview.appendChild(video);
            }
            video.src = url;
        } else {
            var img = preview.querySelector('img');
            if (!img) {
                preview.textContent = '';
                img = document.createElement('img');
                img.alt = '';
                preview.appendChild(img);
            }
            img.src = url;
        }

        if (remove) { remove.hidden = !url; }
        if (zone) { zone.classList.toggle('has-media', !!url); }
        if (pick) { pick.lastChild.textContent = url ? ' Replace' : ' Choose'; }
    }

    /* --------------------------------------------------- local preview */

    /**
     * Show a picked file straight away, before anything is uploaded.
     *
     * The object URL is released once the browser has decoded it - for a
     * video that is `loadeddata`, not `load`, which never fires on <video>.
     */
    function preview(box, file) {
        var url = window.URL.createObjectURL(file);
        paint(box, url);

        var el = box.querySelector('[data-file-preview] img, [data-file-preview] video');
        if (el) {
            el.addEventListener(el.tagName === 'VIDEO' ? 'loadeddata' : 'load', function () {
                window.URL.revokeObjectURL(url);
            }, { once: true });
        }

        // Only meaningful while a choice is pending, hence not in paint().
        var undo = box.querySelector('[data-media-clear]');
        if (undo) { undo.hidden = false; }
    }

    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-file-input]');
        if (!input) { return; }

        var box = imageBox(input);
        var file = input.files && input.files[0];
        if (!box || !file) { return; }

        preview(box, file);
    });

    /* ------------------------------------------------- drag and drop */

    /*
     | dragover has to be cancelled on every event, or the browser takes the
     | drop itself and navigates away from the page to the dropped file -
     | which would lose an unsaved form.
     */
    ['dragenter', 'dragover'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            var zone = event.target.closest && event.target.closest('[data-dropzone]');
            if (!zone) { return; }

            event.preventDefault();
            zone.classList.add('is-dragging');
        });
    });

    document.addEventListener('dragleave', function (event) {
        var zone = event.target.closest && event.target.closest('[data-dropzone]');
        // relatedTarget is where the pointer went; still inside means the
        // drag only crossed a child, so the highlight should stay.
        if (!zone || (event.relatedTarget && zone.contains(event.relatedTarget))) { return; }

        zone.classList.remove('is-dragging');
    });

    document.addEventListener('drop', function (event) {
        var zone = event.target.closest && event.target.closest('[data-dropzone]');
        if (!zone) { return; }

        event.preventDefault();
        zone.classList.remove('is-dragging');

        var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
        var box = imageBox(zone);
        var input = box && box.querySelector('[data-file-input]');
        if (!file || !input) { return; }

        // Hand the file to the real input, so submitting the form carries it
        // exactly as if it had been picked - no separate upload path.
        var transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;

        preview(box, file);
    });

    /* --------------------------------------------------- media buttons */

    document.addEventListener('click', function (event) {
        var pick = event.target.closest('[data-media-pick]');
        if (pick) {
            event.preventDefault();
            var box = imageBox(pick);
            var input = box && box.querySelector('[data-file-input]');
            if (input) { input.click(); }
            return;
        }

        var undo = event.target.closest('[data-media-clear]');
        if (!undo) { return; }

        event.preventDefault();

        var field = imageBox(undo);
        var picker = field && field.querySelector('[data-file-input]');
        if (!picker) { return; }

        picker.value = '';
        undo.hidden = true;

        // Back to whatever is stored on the server, not to empty - undoing a
        // choice must not look like deleting the existing media.
        paint(field, field.getAttribute('data-stored-url') || null);
    });

    /* --------------------------------------------------- remove image */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-image-remove], [data-media-remove]');
        if (!button) { return; }

        event.preventDefault();

        var box = imageBox(button);
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
                    window.Toast.error((payload && payload.message) || 'Could not remove the image.');
                }
                return;
            }

            box.setAttribute('data-stored-url', '');
            paint(box, null);

            // A file the user picked but never sent would otherwise upload
            // itself on the next save, undoing the removal.
            var input = box.querySelector('[data-file-input]');
            if (input) { input.value = ''; }

            var undoChoice = box.querySelector('[data-media-clear]');
            if (undoChoice) { undoChoice.hidden = true; }

            if (window.Toast) { window.Toast.success(payload.message); }
            if (window.AjaxList) { window.AjaxList.reload('[data-ajax-list]'); }
        }).catch(function () {
            button.disabled = false;
            if (window.Toast) {
                window.Toast.error('Could not reach the server. Please try again.');
            }
        });
    });

    /* ------------------------------------------------------ slug helper */

    function slugify(value) {
        return value
            .toLowerCase()
            .trim()
            // Strip accents, so "Café" becomes "cafe" rather than "caf".
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    document.addEventListener('input', function (event) {
        var source = event.target.closest('[data-slug-source]');
        if (!source) { return; }

        var form = source.closest('form');
        var target = form && form.querySelector('[data-slug-target]');
        if (!target) { return; }

        // Stop the moment the slug is edited by hand: on an edit form it
        // already holds a value something may be linking to, and guessing
        // over the top of a deliberate choice is worse than doing nothing.
        if (target.dataset.touched === '1') { return; }

        target.value = slugify(source.value);
    });

    document.addEventListener('input', function (event) {
        var target = event.target.closest('[data-slug-target]');
        if (target) { target.dataset.touched = '1'; }
    });

    /*
     | An edit form arrives with a slug already in it, so it counts as
     | hand-written from the start.
     */
    document.addEventListener('modal:opened', function () {
        document.querySelectorAll('[data-slug-target]').forEach(function (target) {
            if (target.value.trim() !== '') { target.dataset.touched = '1'; }
        });
    });

    /* ------------------------------------------------------ icon picker */

    /*
     | [data-icon-select] chooses a name; the preview beside it shows what
     | that name draws. Every icon is already in the page, hidden, so
     | switching costs nothing and works offline like the rest of the set.
     */
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-icon-select]');
        if (!select) { return; }

        var field = select.closest('.field') || document;
        var preview = field.querySelector('[data-icon-preview]');
        if (!preview) { return; }

        var source = field.querySelector('[data-icon-option="' + select.value + '"]');

        preview.innerHTML = source
            ? source.innerHTML
            // "No icon" leaves the box empty rather than guessing one.
            : '';
    });
})(window, document);
