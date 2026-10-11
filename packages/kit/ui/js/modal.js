/*
|------------------------------------------------------------------------------
| Remote modals
|------------------------------------------------------------------------------
|
| Turns any element into a modal trigger:
|
|   <a href="/admin/profile"
|      data-modal="/admin/profile"
|      data-modal-title="Your Profile"
|      data-modal-size="lg">Profile</a>
|
| The URL is fetched with an `X-Fragment: 1` header - the same contract
| ajax-list.js uses - so the controller can answer with just the inner
| markup instead of a whole page. Keeping a real `href` means the link
| still navigates to the standalone page without JavaScript.
|
| Optional trigger attributes:
|   data-modal-title="..."   heading text (falls back to the trigger's text)
|   data-modal-sub="..."     small line under the heading
|   data-modal-size="sm|lg"  narrower / wider dialog
|
| Anything inside the modal marked [data-modal-close] dismisses it, as do
| Escape and a backdrop click.
|
| Events on the modal root (both bubble):
|   modal:opened   detail = { url, trigger }
|   modal:closed   detail = { url }
|
| Depends on: public/assets/css/theme.css (.modal*)
*/
(function (window, document) {
    'use strict';

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
                    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    var root = null;
    var lastTrigger = null;
    var currentUrl = null;
    var pending = null;

    function build() {
        if (root && document.body.contains(root)) {
            return root;
        }

        root = document.createElement('div');
        root.className = 'modal';
        root.setAttribute('data-modal-root', '');
        root.hidden = true;

        root.innerHTML =
            '<div class="modal-backdrop" data-modal-close></div>' +
            '<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title">' +
                '<div class="modal-head">' +
                    '<div>' +
                        '<h2 class="modal-title" id="modal-title"></h2>' +
                        '<div class="modal-sub" hidden></div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-icon btn-ghost" data-modal-close ' +
                            'aria-label="Close dialog">&times;</button>' +
                '</div>' +
                '<div class="modal-body" data-modal-content></div>' +
            '</div>';

        document.body.appendChild(root);

        return root;
    }

    function dialog() { return build().querySelector('.modal-dialog'); }
    function content() { return build().querySelector('[data-modal-content]'); }

    function focusFirst() {
        var box = dialog();
        // Prefer a real field over the close button, so the modal opens ready
        // to type in rather than ready to dismiss.
        var field = box.querySelector('.modal-body input:not([type="hidden"]), ' +
                                     '.modal-body select, .modal-body textarea');
        var target = field || box.querySelector('[data-modal-close]');
        if (target) { target.focus({ preventScroll: true }); }
    }

    /** Keep Tab inside the dialog while it is open. */
    function trapTab(event) {
        if (event.key !== 'Tab') { return; }

        var items = Array.prototype.filter.call(
            dialog().querySelectorAll(FOCUSABLE),
            function (el) { return el.offsetParent !== null; }
        );

        if (!items.length) { return; }

        var first = items[0];
        var last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function emit(name, detail) {
        build().dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail }));
    }

    function close() {
        if (!root || root.hidden) { return; }

        // Abandon an in-flight fetch so a slow response cannot repopulate a
        // modal the user has already dismissed.
        pending = null;

        var closedUrl = currentUrl;
        currentUrl = null;

        root.classList.remove('is-open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', trapTab, true);

        var finish = function () {
            if (!root || root.classList.contains('is-open')) { return; }
            root.hidden = true;
            content().innerHTML = '';
        };

        dialog().addEventListener('transitionend', finish, { once: true });
        window.setTimeout(finish, 250);

        if (lastTrigger && document.body.contains(lastTrigger)) {
            lastTrigger.focus({ preventScroll: true });
        }
        lastTrigger = null;

        emit('modal:closed', { url: closedUrl });
    }

    function open(url, options) {
        options = options || {};

        var box = build();
        var head = box.querySelector('.modal-title');
        var sub = box.querySelector('.modal-sub');

        head.textContent = options.title || 'Details';

        if (options.sub) {
            sub.textContent = options.sub;
            sub.hidden = false;
        } else {
            sub.hidden = true;
        }

        dialog().className = 'modal-dialog' +
            (options.size === 'sm' ? ' is-sm' : '') +
            (options.size === 'lg' ? ' is-lg' : '');

        content().innerHTML = '<div class="modal-loading">Loading…</div>';

        // A trigger inside a dropdown would otherwise leave it hanging open
        // behind the dialog - the menu only closes on an outside click.
        if (window.AppShell && window.AppShell.closeMenus) {
            window.AppShell.closeMenus();
        }

        currentUrl = url;
        box.hidden = false;
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', trapTab, true);

        window.requestAnimationFrame(function () {
            box.classList.add('is-open');
        });

        var token = {};
        pending = token;

        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Fragment': '1',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            // The user may have closed it, or opened something else, while
            // this was in flight.
            if (pending !== token) { return; }

            content().innerHTML = html;
            focusFirst();
            emit('modal:opened', { url: url, trigger: lastTrigger });
        }).catch(function () {
            if (pending !== token) { return; }

            close();
            if (window.Toast) {
                window.Toast.error('Could not load that. Please try again.');
            }
        });
    }

    /* ------------------------------------------------------------ wiring */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-modal]');
        if (trigger) {
            // Let modified clicks open the standalone page in a new tab.
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }

            event.preventDefault();
            lastTrigger = trigger;

            open(trigger.getAttribute('data-modal') || trigger.getAttribute('href'), {
                title: trigger.getAttribute('data-modal-title') || trigger.textContent.trim(),
                sub: trigger.getAttribute('data-modal-sub'),
                size: trigger.getAttribute('data-modal-size')
            });
            return;
        }

        if (event.target.closest('[data-modal-close]')) {
            event.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root && !root.hidden) {
            close();
        }
    });

    /**
     * Re-fetch what the modal is already showing.
     *
     * For screens whose content is invalidated by an action taken inside
     * them - ending a session changes counts, badges and tab labels at once.
     */
    function reload() {
        if (!currentUrl || !root || root.hidden) { return; }

        open(currentUrl, {
            title: build().querySelector('.modal-title').textContent,
            sub: build().querySelector('.modal-sub').hidden
                ? null
                : build().querySelector('.modal-sub').textContent,
            size: dialog().classList.contains('is-lg')
                ? 'lg'
                : (dialog().classList.contains('is-sm') ? 'sm' : null),
        });
    }

    window.Modal = { open: open, close: close, reload: reload };
})(window, document);
