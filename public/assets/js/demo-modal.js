/*
|------------------------------------------------------------------------------
| "Book a demo" in a window
|------------------------------------------------------------------------------
|
| Every link to /demo on the page (and anything marked data-demo-open) opens the
| <dialog> instead of leaving the page. Esc, the close button and a click on the
| dimmed area close it. Without JavaScript the links simply go to /demo.
*/
(function (window, document) {
    'use strict';

    var dialog = document.querySelector('dialog[data-demo-modal]');

    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var demoPath = '/demo';

    function opens(link) {
        if (link.hasAttribute('data-demo-open')) { return true; }

        try {
            return new URL(link.href, window.location.href).pathname.replace(/\/+$/, '') === demoPath;
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');

        if (!link || link.closest('dialog') || !opens(link)) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }

        event.preventDefault();
        dialog.showModal();
        document.documentElement.classList.add('lp-modal-open');

        var first = dialog.querySelector('input[name="name"]');
        if (first) { first.focus(); }
    });

    function close() {
        if (dialog.open) { dialog.close(); }
    }

    dialog.addEventListener('close', function () {
        document.documentElement.classList.remove('lp-modal-open');
    });

    dialog.addEventListener('click', function (event) {
        // A click on the dimmed area (the dialog element itself) closes it; inside it does not.
        if (event.target === dialog) { close(); }
        if (event.target.closest('[data-demo-modal-close]')) { close(); }
    });
})(window, document);
