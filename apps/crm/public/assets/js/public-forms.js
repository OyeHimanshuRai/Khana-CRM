/* ===========================================================================
   Public forms: submit without a reload, and say what happened
   ===========================================================================

   The marketing site's own script, and the only one it loads. Deliberately
   separate from assets/js/app.js: that file is the admin panel's, it is twenty
   times this size, and it reaches for admin markup this page does not have.

   ---------------------------------------------------------------------------
   An improvement, never a requirement
   ---------------------------------------------------------------------------

   Every form it touches already works on its own - a plain POST, a redirect,
   a flash message. This adds three things on top, and takes nothing away:

     - the post goes through fetch, so what somebody typed stays on screen
       while the server thinks;
     - the answer arrives as a toast rather than as a reloaded page;
     - a field error is drawn beside its own field instead of in a list at
       the top of a page the visitor has been scrolled away from.

   If this file fails to load, is blocked, or throws, the browser submits the
   form itself and the visitor notices nothing. That is the whole design.

   ---------------------------------------------------------------------------
   One envelope
   ---------------------------------------------------------------------------

   Every endpoint answers { success, message, data, redirect, errors } - see
   App\Support\ApiResponse - so there is one shape to read here and no per-form
   special cases.
   ------------------------------------------------------------------------ */

(function () {
    'use strict';

    var forms = document.querySelectorAll('form[data-ajax]');

    if (!forms.length || typeof window.fetch !== 'function') {
        return;
    }

    /* ----------------------------------------------------------- toasts */

    var stack = null;

    function toastStack() {
        if (stack) {
            return stack;
        }

        stack = document.createElement('div');
        stack.className = 'lp-toasts';
        /*
         | Polite, not assertive: a confirmation should be announced when the
         | screen reader finishes its sentence, not over the top of it.
         | Errors are raised to assertive on the toast itself, below.
         */
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);

        return stack;
    }

    function toast(message, kind) {
        if (!message) {
            return;
        }

        var el = document.createElement('div');
        el.className = 'lp-toast' + (kind === 'error' ? ' is-error' : ' is-ok');
        el.textContent = message;

        if (kind === 'error') {
            el.setAttribute('role', 'alert');
        }

        toastStack().appendChild(el);

        // Next frame, so the transition has a state to move away from.
        requestAnimationFrame(function () {
            el.classList.add('is-in');
        });

        var life = kind === 'error' ? 7000 : 4500;

        var close = function () {
            el.classList.remove('is-in');
            setTimeout(function () {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 250);
        };

        el.addEventListener('click', close);
        setTimeout(close, life);
    }

    /* ------------------------------------------------------ field errors */

    function clearErrors(form) {
        form.querySelectorAll('[data-field-error]').forEach(function (node) {
            node.parentNode.removeChild(node);
        });

        form.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
        });
    }

    /**
     * Draw each message beside the field it belongs to.
     *
     * Returns the first field that got one, so the page can scroll to it -
     * on a long form the error is otherwise three screens away and reads as
     * nothing having happened at all.
     */
    function showErrors(form, errors) {
        var first = null;

        Object.keys(errors || {}).forEach(function (field) {
            var messages = errors[field];
            var text = Array.isArray(messages) ? messages[0] : messages;

            // Laravel names nested fields with dots; the input does not.
            var input = form.querySelector('[name="' + field + '"]')
                || form.querySelector('[name="' + field.split('.')[0] + '"]');

            if (!input) {
                return;
            }

            input.setAttribute('aria-invalid', 'true');

            var note = document.createElement('span');
            note.className = 'lp-field-error';
            note.setAttribute('data-field-error', '');
            note.textContent = text;

            (input.closest('.lp-field') || input.parentNode).appendChild(note);

            first = first || input;
        });

        return first;
    }

    /* ------------------------------------------------------------ submit */

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var button = form.querySelector('[type="submit"]');
            var busyLabel = form.dataset.busy || 'Working…';
            var idleLabel = button ? button.textContent : '';

            if (button) {
                button.disabled = true;
                button.textContent = busyLabel;
            }

            clearErrors(form);

            fetch(form.action, {
                method: (form.method || 'POST').toUpperCase(),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new FormData(form)
            }).then(function (response) {
                return response.json().then(function (body) {
                    return { status: response.status, body: body };
                });
            }).then(function (result) {
                var body = result.body || {};

                if (body.success) {
                    toast(body.message, 'ok');
                    form.reset();

                    if (body.redirect) {
                        // Long enough to read the toast, short enough that
                        // nobody wonders whether the click registered.
                        setTimeout(function () {
                            window.location.assign(body.redirect);
                        }, 700);

                        return;
                    }

                    if (button) {
                        button.disabled = false;
                        button.textContent = idleLabel;
                    }

                    return;
                }

                var target = showErrors(form, body.errors);

                toast(
                    body.message || 'Please check the form and try again.',
                    'error'
                );

                if (target) {
                    target.focus({ preventScroll: true });
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                if (button) {
                    button.disabled = false;
                    button.textContent = idleLabel;
                }
            }).catch(function () {
                /*
                 | The network, not the form.
                 |
                 | Deliberately not a silent re-submit: the first post may
                 | well have reached the server, and the one thing worse than
                 | an error here is two accounts.
                 */
                toast('That did not reach us. Check your connection and try again.', 'error');

                if (button) {
                    button.disabled = false;
                    button.textContent = idleLabel;
                }
            });
        });
    });
})();
