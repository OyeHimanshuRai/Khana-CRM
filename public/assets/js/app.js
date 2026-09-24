/*
|------------------------------------------------------------------------------
| Shared front-end helpers
|------------------------------------------------------------------------------
|
| Exposes two globals, both reusable by any page in the app:
|
|   Toast.success(msg) / .error(msg) / .warning(msg) / .info(msg)
|   AjaxForm  - auto-submits every <form data-ajax> without a page reload
|
| Any form marked `data-ajax` is posted with fetch(), and the JSON envelope
| returned by json_success()/json_error() drives the toasts and the inline
| field errors. Forms without the attribute behave normally, so the pages
| still work with JavaScript disabled.
|
| Optional form attributes:
|   data-ajax                     enable AJAX submission
|   data-toast-success="false"    stay silent on success
|   data-redirect-delay="700"     ms to wait before following `redirect`
|   data-reset-on-success         clear the form after a successful submit
|   data-close-modal              dismiss the modal the form sits in
|   data-refresh-list             reload the page's [data-ajax-list]
|
| The last two are what make a modal form feel finished: save, the dialog
| closes, and the listing behind it shows the change - with no reload.
|
| Events dispatched on the form (both bubble, detail = parsed payload):
|   ajax:success, ajax:error
|
| Depends on: public/assets/css/app.css
*/
(function (window, document) {
    'use strict';

    /* ------------------------------------------------------------ toasts */

    var TOAST_DEFAULT_DURATION = 5000;

    var ICONS = {
        success: '✓',
        error: '✕',
        warning: '!',
        info: 'i'
    };

    var TITLES = {
        success: 'Success',
        error: 'Error',
        warning: 'Warning',
        info: 'Notice'
    };

    var stack = null;

    function stackEl() {
        if (stack && document.body.contains(stack)) {
            return stack;
        }

        stack = document.querySelector('.toast-stack');

        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            // Polite so a screen reader finishes the current phrase first;
            // errors are upgraded to assertive per-toast below.
            stack.setAttribute('aria-live', 'polite');
            stack.setAttribute('aria-atomic', 'false');
            document.body.appendChild(stack);
        }

        return stack;
    }

    function buildMessage(message) {
        // An array renders as a bullet list (handy for validation summaries).
        if (!Array.isArray(message)) {
            var single = document.createElement('div');
            single.className = 'toast-message';
            single.textContent = String(message);
            return single;
        }

        var wrap = document.createElement('div');
        wrap.className = 'toast-message';

        if (message.length === 1) {
            wrap.textContent = message[0];
            return wrap;
        }

        var list = document.createElement('ul');
        message.forEach(function (line) {
            var li = document.createElement('li');
            li.textContent = line;
            list.appendChild(li);
        });
        wrap.appendChild(list);

        return wrap;
    }

    function dismiss(toast) {
        if (!toast || toast.dataset.leaving === '1') {
            return;
        }

        toast.dataset.leaving = '1';
        toast.classList.remove('is-visible');
        toast.classList.add('is-leaving');

        var done = function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        };

        toast.addEventListener('transitionend', done, { once: true });
        // Fallback in case the transition never fires (reduced motion, hidden tab).
        window.setTimeout(done, 400);
    }

    /**
     * Show a toast.
     *
     * @param {string}        type     success | error | warning | info
     * @param {string|array}  message  text, or a list of lines
     * @param {object}        options  { title, duration }  duration 0 = sticky
     */
    function notify(type, message, options) {
        options = options || {};

        if (message === null || typeof message === 'undefined' || message === '') {
            return null;
        }

        var kind = ICONS.hasOwnProperty(type) ? type : 'info';
        var duration = typeof options.duration === 'number'
            ? options.duration
            : TOAST_DEFAULT_DURATION;

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + kind;
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');

        var icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = ICONS[kind];

        var body = document.createElement('div');
        body.className = 'toast-body';

        var title = document.createElement('div');
        title.className = 'toast-title';
        title.textContent = options.title || TITLES[kind];

        body.appendChild(title);
        body.appendChild(buildMessage(message));

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () {
            dismiss(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(body);
        toast.appendChild(close);

        if (duration > 0) {
            var bar = document.createElement('span');
            bar.className = 'toast-progress';
            bar.style.animationDuration = duration + 'ms';
            toast.appendChild(bar);

            var timer = window.setTimeout(function () {
                dismiss(toast);
            }, duration);

            // Hovering holds the toast open so it can actually be read.
            toast.addEventListener('mouseenter', function () {
                window.clearTimeout(timer);
                bar.style.animationPlayState = 'paused';
            });

            toast.addEventListener('mouseleave', function () {
                bar.style.animationPlayState = 'running';
                timer = window.setTimeout(function () {
                    dismiss(toast);
                }, 1200);
            });
        }

        stackEl().appendChild(toast);

        // Next frame, so the entry transition has a starting state to animate from.
        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        return toast;
    }

    var Toast = {
        show: notify,
        success: function (m, o) { return notify('success', m, o); },
        error: function (m, o) { return notify('error', m, o); },
        warning: function (m, o) { return notify('warning', m, o); },
        info: function (m, o) { return notify('info', m, o); },
        clear: function () {
            Array.prototype.forEach.call(
                stackEl().querySelectorAll('.toast'),
                dismiss
            );
        }
    };

    /* --------------------------------------------------------- ajax forms */

    function csrfToken(form) {
        var field = form.querySelector('input[name="_token"]');
        if (field && field.value) {
            return field.value;
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function fieldContainer(input) {
        return input.closest('.field') || input.parentNode;
    }

    function clearErrors(form) {
        Array.prototype.forEach.call(
            form.querySelectorAll('.field-error'),
            function (node) { node.parentNode.removeChild(node); }
        );

        Array.prototype.forEach.call(
            form.querySelectorAll('.is-invalid'),
            function (node) {
                node.classList.remove('is-invalid');
                node.setAttribute('aria-invalid', 'false');
            }
        );
    }

    /**
     * Paint Laravel's `errors` bag onto the matching inputs.
     *
     * @return {array} flat list of messages, for the toast summary
     */
    function renderErrors(form, errors) {
        var lines = [];
        var firstInvalid = null;

        Object.keys(errors || {}).forEach(function (name) {
            var raw = errors[name];
            var messages = Array.isArray(raw) ? raw : [raw];
            if (!messages.length) {
                return;
            }

            lines.push(messages[0]);

            // `user[email]` style names, and array inputs, both need escaping.
            var input = form.querySelector('[name="' + (window.CSS && CSS.escape ? CSS.escape(name) : name) + '"]')
                || form.querySelector('[name="' + name + '[]"]');

            if (!input) {
                return;
            }

            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');

            var span = document.createElement('span');
            span.className = 'field-error';
            span.setAttribute('role', 'alert');
            span.textContent = messages[0];
            fieldContainer(input).appendChild(span);

            if (!firstInvalid) {
                firstInvalid = input;
            }
        });

        if (firstInvalid) {
            firstInvalid.focus({ preventScroll: false });
        }

        return lines;
    }

    function setBusy(button, busy) {
        if (!button) {
            return;
        }

        if (busy) {
            // Wrap the label so the spinner can hide it without losing markup.
            if (!button.querySelector('.btn-label')) {
                var label = document.createElement('span');
                label.className = 'btn-label';
                while (button.firstChild) {
                    label.appendChild(button.firstChild);
                }
                button.appendChild(label);
            }
            button.classList.add('is-busy');
            button.disabled = true;
        } else {
            button.classList.remove('is-busy');
            button.disabled = false;
        }
    }

    function emit(form, name, detail) {
        form.dispatchEvent(new CustomEvent(name, {
            bubbles: true,
            detail: detail
        }));
    }

    /**
     * The two things a modal form usually wants after it saves: get out of
     * the way, and let the listing behind it catch up.
     *
     * Both are opt-in attributes rather than defaults, so a form that should
     * stay open after saving simply does not ask for them.
     */
    function afterSuccess(form) {
        if (form.hasAttribute('data-close-modal') && window.Modal) {
            window.Modal.close();
        }

        if (!form.hasAttribute('data-refresh-list') || !window.AjaxList) {
            return;
        }

        // Named list, or the only one on the page.
        var selector = form.getAttribute('data-refresh-list');
        var list = selector
            ? document.querySelector(selector)
            : document.querySelector('[data-ajax-list]');

        if (list) { window.AjaxList.reload(list); }
    }

    /**
     * Where a form posts to.
     *
     * The attribute, not the property: a field named `action` (or `method`,
     * or `id`) shadows the matching property on HTMLFormElement, so
     * `form.action` would hand back that element instead of the URL and the
     * request would go to "[object HTMLSelectElement]". Relative values
     * resolve against the document either way.
     */
    function formAction(form) {
        return form.getAttribute('action') || window.location.href;
    }

    function formMethod(form) {
        return (form.getAttribute('method') || 'POST').toUpperCase();
    }

    function handleSubmit(event) {
        var form = event.target;

        if (!form.matches('form[data-ajax]') || form.dataset.submitting === '1') {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
            }
            return;
        }

        event.preventDefault();

        var button = form.querySelector('[type="submit"]') ||
                     document.querySelector('[form="' + form.id + '"][type="submit"]');

        form.dataset.submitting = '1';
        clearErrors(form);
        setBusy(button, true);

        var release = function () {
            delete form.dataset.submitting;
            setBusy(button, false);
        };

        fetch(formAction(form), {
            method: formMethod(form),
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(form)
            }
        }).then(function (response) {
            // A non-JSON body means something upstream broke (HTML error page).
            return response.text().then(function (text) {
                var payload;
                try {
                    payload = text ? JSON.parse(text) : {};
                } catch (e) {
                    payload = null;
                }
                return { ok: response.ok, status: response.status, payload: payload };
            });
        }).then(function (result) {
            var payload = result.payload;

            if (payload === null) {
                release();
                Toast.error('The server returned an unexpected response. Please try again.');
                emit(form, 'ajax:error', { status: result.status, payload: null });
                return;
            }

            if (result.ok && payload.success) {
                if (form.dataset.toastSuccess !== 'false') {
                    Toast.success(payload.message || 'Done.');
                }

                if (form.hasAttribute('data-reset-on-success')) {
                    form.reset();
                }

                emit(form, 'ajax:success', payload);

                afterSuccess(form);

                if (payload.redirect) {
                    var delay = parseInt(form.dataset.redirectDelay, 10);
                    window.setTimeout(function () {
                        window.location.assign(payload.redirect);
                    }, isNaN(delay) ? 700 : delay);
                    // Deliberately stay busy: the page is on its way out.
                    return;
                }

                release();
                return;
            }

            release();

            var lines = renderErrors(form, payload.errors);
            var message = payload.message || 'Please correct the highlighted fields.';

            // Prefer listing the actual field problems over the generic
            // "The given data was invalid." wrapper Laravel sends.
            Toast.error(lines.length ? lines : message);

            emit(form, 'ajax:error', { status: result.status, payload: payload });
        }).catch(function () {
            release();
            Toast.error('Could not reach the server. Check your connection and try again.');
            emit(form, 'ajax:error', { status: 0, payload: null });
        });
    }

    // Delegated, so forms added to the DOM later are handled too.
    document.addEventListener('submit', handleSubmit);

    /* ------------------------------------------------ password reveal */

    /*
     | <button data-toggle-password aria-controls="password">Show</button>
     |
     | Delegated rather than bound on load, so password fields inside a
     | modal or any other injected markup work without extra wiring.
     */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-password]');
        if (!button) {
            return;
        }

        // Scoped to the containing form first: the same fragment can be on
        // the page and inside a modal at once, and getElementById would then
        // always return the copy underneath.
        var id = button.getAttribute('aria-controls');
        var scope = button.closest('form') || document;
        var input = scope.querySelector('[id="' + id + '"]') || document.getElementById(id);

        if (!input) {
            return;
        }

        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(reveal));
        input.focus({ preventScroll: true });
    });

    /* ------------------------------------------------ table row select */

    /*
     | Bulk selection for any listing table:
     |   [data-check-all]   header checkbox
     |   [data-check-item]  per-row checkbox
     |   [data-bulk-bar]    container revealed while a selection exists
     |   [data-bulk-count]  element receiving the selected count
     |   [data-bulk-ids]    hidden field(s) filled with the ids, comma separated
     |
     | Scoped to the closest [data-bulk-scope], so several tables can coexist.
     */

    function bulkScope(el) {
        return el.closest('[data-bulk-scope]') || document;
    }

    function bulkItems(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-check-item]'));
    }

    function refreshBulk(scope) {
        var items = bulkItems(scope);
        var selected = items.filter(function (i) { return i.checked; });

        var master = scope.querySelector('[data-check-all]');
        if (master) {
            master.checked = items.length > 0 && selected.length === items.length;
            master.indeterminate = selected.length > 0 && selected.length < items.length;
        }

        var bar = scope.querySelector('[data-bulk-bar]');
        if (bar) { bar.hidden = selected.length === 0; }

        var count = scope.querySelector('[data-bulk-count]');
        if (count) { count.textContent = String(selected.length); }

        // Every field, not just the first: a bulk bar can hold more than one
        // form - "apply an action" and "export the selection" post to
        // different endpoints and both need the same ids.
        var joined = selected.map(function (i) { return i.value; }).join(',');
        Array.prototype.forEach.call(
            scope.querySelectorAll('[data-bulk-ids]'),
            function (field) { field.value = joined; }
        );

        selected.forEach(function (i) {
            var row = i.closest('tr');
            if (row) { row.classList.add('is-selected'); }
        });

        items.filter(function (i) { return !i.checked; }).forEach(function (i) {
            var row = i.closest('tr');
            if (row) { row.classList.remove('is-selected'); }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target.matches('[data-check-all]')) {
            var scope = bulkScope(target);
            bulkItems(scope).forEach(function (i) { i.checked = target.checked; });
            refreshBulk(scope);
            return;
        }

        if (target.matches('[data-check-item]')) {
            refreshBulk(bulkScope(target));
        }
    });

    /* ------------------------------------------------- flashed messages */

    function flushFlashed() {
        var node = document.getElementById('flash-messages');
        if (!node) {
            return;
        }

        var items;
        try {
            items = JSON.parse(node.textContent || '[]');
        } catch (e) {
            return;
        }

        items.forEach(function (item) {
            notify(item.type, item.message);
        });
    }

    function boot() {
        flushFlashed();
        document.querySelectorAll('[data-bulk-scope]').forEach(refreshBulk);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.Toast = Toast;
    // Exposed so ajax-list.js can restore selection state after swapping rows.
    window.BulkSelect = { refresh: refreshBulk };
    window.AjaxForm = {
        submit: handleSubmit,
        clearErrors: clearErrors,
        renderErrors: renderErrors
    };
})(window, document);
