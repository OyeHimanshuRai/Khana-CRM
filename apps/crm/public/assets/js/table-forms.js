/* ===========================================================================
   The guest's forms: submit without a reload, and say what happened
   ===========================================================================

   Small on purpose, and small is the whole specification. This runs on
   whatever phone somebody walked in with, over restaurant wifi, and a menu
   that needs a bundle to download before anybody can order fails at the exact
   moment it matters. So: no admin bundle (assets/js/app.js is twenty times
   this size and reaches for admin markup none of these pages have), no
   marketing bundle (public-forms.js draws .lp-toast, which table.css knows
   nothing about), and nothing here that the page cannot do without.

   ---------------------------------------------------------------------------
   An improvement, never a requirement
   ---------------------------------------------------------------------------

   Every form it touches already works on its own: a real action, a real
   method, a plain POST, a redirect and the flash partial. This adds a toast
   and keeps the guest's scroll position, and takes nothing away. If the file
   is blocked, slow, cached out or throws, the browser submits the form itself
   and the guest notices nothing.

   Only forms marked data-ajax are touched. The cart's quantity and remove
   buttons deliberately are not - see the comment on them in cart.blade.php.

   ---------------------------------------------------------------------------
   One envelope
   ---------------------------------------------------------------------------

   Every endpoint answers { success, message, data, redirect, errors } - see
   App\Support\ApiResponse - so there is one shape to read here.
   ------------------------------------------------------------------------ */

(function () {
    'use strict';

    if (typeof window.fetch !== 'function') {
        return;
    }

    /* ----------------------------------------------------------- toasts */

    var stack = null;

    function toast(message, kind) {
        if (!message) {
            return;
        }

        if (!stack) {
            stack = document.createElement('div');
            stack.className = 't-toasts';
            /*
             | Polite, not assertive: a confirmation should be announced when
             | the screen reader finishes its sentence rather than over the
             | top of it. Errors raise themselves on the toast, below - the
             | same split the flash partial already makes.
             */
            stack.setAttribute('role', 'status');
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }

        var el = document.createElement('div');
        el.className = 't-toast' + (kind === 'error' ? ' is-error' : ' is-ok');
        el.textContent = message;

        if (kind === 'error') {
            el.setAttribute('role', 'alert');
        }

        stack.appendChild(el);

        // Next frame, so the transition has a state to move away from.
        requestAnimationFrame(function () {
            el.classList.add('is-in');
        });

        var close = function () {
            el.classList.remove('is-in');
            setTimeout(function () {
                if (el.parentNode) { el.parentNode.removeChild(el); }
            }, 250);
        };

        el.addEventListener('click', close);
        setTimeout(close, kind === 'error' ? 7000 : 4500);
    }

    /* --------------------------------------------------------- cart bar */

    /**
     * The one thing on the menu derived from the cart.
     *
     * A count left over from before the tap is worse than a reload, so the
     * server sends the new one back with every add and the bar is redrawn
     * from that rather than from arithmetic done here.
     */
    function cartBar(count) {
        var bar = document.querySelector('[data-cart-bar]');

        if (!bar || typeof count !== 'number') {
            return;
        }

        var label = bar.querySelector('[data-cart-count]');

        if (label) {
            label.textContent = count + ' item' + (count === 1 ? '' : 's');
        }

        bar.hidden = count < 1;
    }

    /* ------------------------------------------------------------ submit */

    function handleSubmit(event) {
        var form = event.target;

        if (!form.matches || !form.matches('form[data-ajax]')) {
            return;
        }

        // A second tap while the first is in flight would send a second
        // ticket to the kitchen.
        if (form.dataset.sending === '1') {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        form.dataset.sending = '1';

        var button = form.querySelector('[type="submit"]');
        var idle = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            if (form.dataset.busy) { button.textContent = form.dataset.busy; }
        }

        var release = function () {
            delete form.dataset.sending;

            if (button) {
                button.disabled = false;
                button.textContent = idle;
            }
        };

        fetch(form.getAttribute('action') || window.location.href, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new FormData(form)
        }).then(function (response) {
            // A body that is not JSON means something upstream broke and sent
            // an HTML page; parsing it as an answer would show the guest a
            // fragment of a stack trace.
            return response.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return null;
                }
            });
        }).then(function (body) {
            if (body === null) {
                release();
                toast('Something went wrong. Please reload the page and try again.', 'error');
                return;
            }

            if (!body.success) {
                release();
                /*
                 | One line, like the flash partial: these forms are radio
                 | groups and number boxes, and a hungry guest needs to know
                 | what to change rather than which input name failed.
                 */
                toast(body.message || 'Please check what you entered and try again.', 'error');
                return;
            }

            toast(body.message, 'ok');
            cartBar(body.data ? body.data.cart_count : null);

            if (body.redirect) {
                // Long enough to read the toast, short enough that nobody
                // wonders whether the tap registered. Deliberately still
                // busy: the page is on its way out.
                setTimeout(function () {
                    window.location.assign(body.redirect);
                }, 700);

                return;
            }

            form.reset();

            // The dish folds itself back up, the way it does when the page
            // comes back from a plain POST.
            var open = form.querySelector('details[open]');
            if (open) { open.open = false; }

            release();
        }).catch(function () {
            /*
             | The network, not the form.
             |
             | Deliberately not a silent retry: the post may well have reached
             | the kitchen, and the one thing worse than an error here is two
             | tickets for the same food.
             */
            release();
            toast('That did not reach us. Check your connection and try again.', 'error');
        });
    }

    // Delegated from the document, so one listener covers every dish card on
    // a menu of ninety of them.
    document.addEventListener('submit', handleSubmit);
})();
