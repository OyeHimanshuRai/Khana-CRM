/*
 * The thin progress bar along the top edge while the browser is moving to
 * another page.
 *
 * A server-rendered page that takes a second (a report, the dashboard) otherwise
 * leaves the old page frozen with nothing to say a click was heard. This is the
 * "yes, going" - and nothing more: it does not measure anything, it eases most
 * of the way across and waits for the new page to replace it.
 *
 * Only a real navigation starts it: a plain same-origin link or a form submit
 * that nothing else has taken over. Not a link that opens a dialog or an ajax
 * action (those have their own feedback and call preventDefault first - this
 * file loads last, so it sees that), not a new tab, not a download, not a
 * same-page #anchor.
 *
 * It waits 120ms before showing, so a page that answers at once never flashes
 * it, and gives up after 12s - a download keeps the page open, and a bar that
 * never finishes would say the page is still loading when it is not.
 */
(function (window, document) {
    'use strict';

    var SHOW_AFTER = 120;
    var GIVE_UP_AFTER = 12000;
    var NOT_A_PAGE = /(^|[/?&=._-])(export|download|print|pdf|csv|xlsx?)([/?&=._-]|$)/i;

    var bar = document.createElement('div');
    bar.className = 'topbar';
    bar.setAttribute('aria-hidden', 'true');

    var showTimer = null;
    var giveUpTimer = null;

    function mount() {
        if (!bar.parentNode && document.body) { document.body.appendChild(bar); }
    }

    function start() {
        mount();
        clearTimeout(showTimer);
        clearTimeout(giveUpTimer);
        showTimer = setTimeout(function () {
            bar.classList.remove('is-done');
            // Restart the transition from zero if a previous run left it mid-way.
            bar.classList.remove('is-active');
            void bar.offsetWidth;
            bar.classList.add('is-active');
        }, SHOW_AFTER);
        giveUpTimer = setTimeout(finish, GIVE_UP_AFTER);
    }

    function finish() {
        clearTimeout(showTimer);
        clearTimeout(giveUpTimer);
        if (bar.classList.contains('is-active')) {
            bar.classList.add('is-done');
            bar.classList.remove('is-active');
        }
    }

    function isPlainNavigation(link) {
        if (link.target && link.target !== '_self') { return false; }
        if (link.hasAttribute('download')) { return false; }

        // Behaviours that own their own feedback.
        if (link.closest('[data-modal], [data-ajax], [data-ajax-page], [data-ajax-reset], [data-dropdown-toggle], [data-theme-toggle], [data-sidebar-toggle]')) {
            return false;
        }

        var raw = link.getAttribute('href') || '';
        if (raw === '' || raw.charAt(0) === '#' || /^(mailto:|tel:|javascript:|sms:)/i.test(raw)) { return false; }

        var url;
        try { url = new URL(link.href, window.location.href); } catch (e) { return false; }

        if (url.origin !== window.location.origin) { return false; }

        // Same page, different anchor: nothing loads.
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '') {
            return false;
        }

        // A file, not a page: the document stays put, so a bar would never end.
        return !NOT_A_PAGE.test(url.pathname + url.search);
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }

        var link = event.target.closest && event.target.closest('a[href]');
        if (link && isPlainNavigation(link)) { start(); }
    });

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) { return; }

        var form = event.target;
        if (!form || form.target === '_blank' || form.hasAttribute('data-ajax')) { return; }

        var action = form.getAttribute('action') || window.location.href;
        if (NOT_A_PAGE.test(action)) { return; }

        start();
    });

    /* Back/forward can restore a page from memory with the bar still running. */
    window.addEventListener('pageshow', function (event) { if (event.persisted) { finish(); } });

    /* Exposed for code that moves the page itself (window.location = ...). */
    window.TopBar = { start: start, finish: finish };
})(window, document);
