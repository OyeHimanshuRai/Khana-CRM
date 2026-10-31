/*
 * The command menu: press Ctrl+K (⌘K on a Mac), type a few letters of where you
 * want to be, press Enter.
 *
 * Why it earns a place here. The sidebar has about forty screens across a dozen
 * groups, a manager works in perhaps six of them, and the rest are two clicks
 * deep behind a submenu - or, in the collapsed rail, behind an expansion. The
 * search box in the header used to be a decoration; this is what it is for.
 *
 * It builds itself from the sidebar rather than from a list of its own. The
 * sidebar is already filtered to what this person may open (permissions) and
 * what this shop has switched on (modules), so the menu can never offer a screen
 * that would answer "access denied" - and it needs no second place to keep up to
 * date when a screen is added.
 *
 * Keyboard first, and honest about it: arrows move, Enter opens, Escape closes,
 * and the keys are drawn on the page where they work. "/" does the sensible thing
 * for the screen you are on - it jumps to the listing's own search box when there
 * is one, and opens this when there is not.
 */
(function (window, document) {
    'use strict';

    var RECENT_KEY = 'erp.cmdk.recent';
    var MAX_RESULTS = 9;
    var isMac = /Mac|iPhone|iPad/.test(window.navigator.platform || window.navigator.userAgent || '');

    var items = null;      // built on first open
    var root = null;       // the dialog, built on first open
    var input = null;
    var list = null;
    var live = null;
    var active = 0;
    var shown = [];
    var lastFocus = null;

    /* ------------------------------------------------------------- the items */

    function collect() {
        var out = [];
        var group = '';
        var nodes = document.querySelectorAll('.sidebar-nav .nav-heading, .sidebar-nav a.nav-link[href]');

        Array.prototype.forEach.call(nodes, function (node) {
            if (node.classList.contains('nav-heading')) {
                group = (node.textContent || '').trim();
                return;
            }

            if (node.hasAttribute('data-soon') || node.getAttribute('href') === '#') { return; }

            var label = ((node.querySelector('.nav-text') || node).textContent || '').trim();
            if (!label) { return; }

            var parent = '';
            var sub = node.closest('.nav-sub');
            if (sub && sub.previousElementSibling) {
                parent = ((sub.previousElementSibling.querySelector('.nav-text') || {}).textContent || '').trim();
            }

            // A submenu link has no glyph of its own; it wears its section's.
            var icon = node.querySelector('svg') ||
                (sub && sub.previousElementSibling ? sub.previousElementSibling.querySelector('svg') : null);

            out.push({
                label: label,
                where: [group, parent].filter(Boolean).join(' › '),
                href: node.href,
                icon: icon ? icon.outerHTML : '',
                haystack: (label + ' ' + parent + ' ' + group).toLowerCase(),
                current: node.classList.contains('is-active')
            });
        });

        // Things that are not places, but that people look for by name.
        var theme = document.querySelector('[data-theme-toggle]');
        if (theme) {
            out.push({
                label: 'Toggle dark mode',
                where: 'Appearance',
                action: function () { theme.click(); },
                icon: '',
                haystack: 'toggle dark mode light theme appearance night'
            });
        }

        return out;
    }

    /* ------------------------------------------------------------- matching */

    /* Word-start beats anywhere beats scattered letters; a shorter label wins a tie. */
    function score(item, query) {
        var h = item.haystack;
        var label = item.label.toLowerCase();

        if (label === query) { return 1000; }
        if (label.indexOf(query) === 0) { return 900 - label.length; }

        var at = h.indexOf(' ' + query);
        if (at !== -1 || h.indexOf(query) === 0) { return 700 - label.length; }

        at = h.indexOf(query);
        if (at !== -1) { return 500 - at; }

        // Letters in order, anywhere: "pbill" finds "POS Billing".
        var i = 0;
        for (var c = 0; c < h.length && i < query.length; c++) {
            if (h.charAt(c) === query.charAt(i)) { i++; }
        }
        return i === query.length ? 100 - h.length / 10 : 0;
    }

    function recent() {
        try { return JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; }
    }

    function remember(href) {
        try {
            var next = [href].concat(recent().filter(function (h) { return h !== href; })).slice(0, 5);
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
        } catch (e) { /* not remembered; nothing else depends on it */ }
    }

    function results(query) {
        query = query.trim().toLowerCase();

        if (query === '') {
            var back = recent();
            var recents = back
                .map(function (href) { return items.filter(function (i) { return i.href === href; })[0]; })
                .filter(Boolean)
                .filter(function (i) { return !i.current; });

            var rest = items.filter(function (i) { return recents.indexOf(i) === -1 && !i.current && i.href; });
            return recents.map(function (i) { return { item: i, tag: 'Recent' }; })
                .concat(rest.slice(0, Math.max(0, MAX_RESULTS - recents.length)).map(function (i) { return { item: i, tag: '' }; }));
        }

        return items
            .map(function (item) { return { item: item, s: score(item, query) }; })
            .filter(function (r) { return r.s > 0; })
            .sort(function (a, b) { return b.s - a.s; })
            .slice(0, MAX_RESULTS)
            .map(function (r) { return { item: r.item, tag: '' }; });
    }

    /* ----------------------------------------------------------------- view */

    function esc(text) {
        return String(text).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function render() {
        shown = results(input.value);
        active = Math.min(active, Math.max(0, shown.length - 1));

        if (shown.length === 0) {
            list.innerHTML = '<li class="cmdk-empty" role="presentation">Nothing matches &ldquo;' + esc(input.value.trim()) + '&rdquo;.</li>';
            input.removeAttribute('aria-activedescendant');
            live.textContent = 'No results';
            return;
        }

        list.innerHTML = shown.map(function (r, i) {
            var item = r.item;
            return '<li role="option" id="cmdk-opt-' + i + '" class="cmdk-row' + (i === active ? ' is-active' : '') + '"' +
                ' aria-selected="' + (i === active ? 'true' : 'false') + '" data-index="' + i + '">' +
                '<span class="cmdk-icon" aria-hidden="true">' + item.icon + '</span>' +
                '<span class="cmdk-label">' + esc(item.label) + '</span>' +
                (r.tag ? '<span class="cmdk-tag">' + r.tag + '</span>' : '') +
                '<span class="cmdk-where">' + esc(item.where) + '</span>' +
                '</li>';
        }).join('');

        input.setAttribute('aria-activedescendant', 'cmdk-opt-' + active);
        live.textContent = shown.length + (shown.length === 1 ? ' result' : ' results');
    }

    function setActive(next, scroll) {
        if (shown.length === 0) { return; }
        active = (next + shown.length) % shown.length;

        Array.prototype.forEach.call(list.children, function (row, i) {
            var on = i === active;
            row.classList.toggle('is-active', on);
            row.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on && scroll) { row.scrollIntoView({ block: 'nearest' }); }
        });

        input.setAttribute('aria-activedescendant', 'cmdk-opt-' + active);
    }

    function build() {
        root = document.createElement('div');
        root.className = 'cmdk';
        root.hidden = true;
        root.innerHTML =
            '<div class="cmdk-scrim" data-cmdk-close></div>' +
            '<div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Command menu">' +
                '<div class="cmdk-field">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
                    '<input type="text" class="cmdk-input" role="combobox" aria-expanded="true" aria-controls="cmdk-list" ' +
                        'aria-autocomplete="list" autocomplete="off" autocapitalize="off" spellcheck="false" ' +
                        'placeholder="Go to a screen…" aria-label="Go to a screen">' +
                '</div>' +
                '<ul class="cmdk-list" id="cmdk-list" role="listbox"></ul>' +
                '<div class="cmdk-foot" data-kbd-hint>' +
                    '<span class="kbd-group"><kbd class="kbd">↑</kbd><kbd class="kbd">↓</kbd> move</span>' +
                    '<span class="kbd-group"><kbd class="kbd">↵</kbd> open</span>' +
                    '<span class="kbd-group"><kbd class="kbd">esc</kbd> close</span>' +
                '</div>' +
                '<div class="sr-only" aria-live="polite"></div>' +
            '</div>';

        document.body.appendChild(root);

        input = root.querySelector('.cmdk-input');
        list = root.querySelector('.cmdk-list');
        live = root.querySelector('[aria-live]');

        input.addEventListener('input', function () { active = 0; render(); });

        input.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowDown': event.preventDefault(); setActive(active + 1, true); break;
                case 'ArrowUp': event.preventDefault(); setActive(active - 1, true); break;
                case 'n': if (event.ctrlKey) { event.preventDefault(); setActive(active + 1, true); } break;
                case 'p': if (event.ctrlKey) { event.preventDefault(); setActive(active - 1, true); } break;
                case 'Enter': event.preventDefault(); choose(active); break;
                case 'Escape': event.preventDefault(); close(); break;
                case 'Tab': event.preventDefault(); break;   // the input is the only stop
            }
        });

        list.addEventListener('mousemove', function (event) {
            var row = event.target.closest('.cmdk-row');
            if (row) { var i = parseInt(row.dataset.index, 10); if (i !== active) { setActive(i, false); } }
        });

        list.addEventListener('click', function (event) {
            var row = event.target.closest('.cmdk-row');
            if (row) { choose(parseInt(row.dataset.index, 10)); }
        });

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-cmdk-close]')) { close(); }
        });
    }

    function choose(index) {
        var entry = shown[index];
        if (!entry) { return; }
        var item = entry.item;

        close(true);

        if (item.action) { item.action(); return; }

        remember(item.href);
        if (window.TopBar) { window.TopBar.start(); }
        window.location.assign(item.href);
    }

    function open() {
        if (!document.querySelector('.sidebar-nav')) { return; }   // no shell (sign-in): nothing to jump to
        if (!root) { build(); }
        if (items === null) { items = collect(); }

        lastFocus = document.activeElement;
        input.value = '';
        active = 0;
        render();

        root.hidden = false;
        document.body.classList.add('cmdk-open');
        // Next frame, so the transition has a "before" to start from.
        window.requestAnimationFrame(function () {
            root.classList.add('is-open');
            input.focus();
        });
    }

    function close(keepFocus) {
        if (!root || root.hidden) { return; }

        root.classList.remove('is-open');
        document.body.classList.remove('cmdk-open');

        // Hidden after the fade, not during it - and not at all if it was reopened.
        window.setTimeout(function () { if (!root.classList.contains('is-open')) { root.hidden = true; } }, 140);

        if (!keepFocus && lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    /* ------------------------------------------------------------- triggers */

    function typing(target) {
        return !!target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    document.addEventListener('keydown', function (event) {
        var mod = event.ctrlKey || event.metaKey;

        if (mod && !event.shiftKey && !event.altKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (root && !root.hidden) { close(); } else { open(); }
            return;
        }

        if (event.key === '/' && !mod && !event.altKey && !typing(event.target) && !document.body.classList.contains('modal-open')) {
            // On a listing, "/" means "search this list"; anywhere else, "go to".
            var local = document.querySelector('.list-search input');
            event.preventDefault();
            if (local) { local.focus(); local.select(); } else { open(); }
        }
    });

    document.addEventListener('click', function (event) {
        // A click as well as focus: a field that is already focused gets no second focusin.
        if (event.target.closest('[data-command-open], [data-command-field]')) { event.preventDefault(); open(); }
    });

    // The header's search box is the front door of this menu.
    document.addEventListener('focusin', function (event) {
        if (event.target.matches && event.target.matches('[data-command-field]')) {
            event.target.blur();
            open();
        }
    });

    /* ⌘ for a Mac, Ctrl everywhere else, wherever the hint is drawn. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-kbd-mod]'), function (el) {
        el.textContent = isMac ? '⌘' : 'Ctrl';
    });

    window.CommandMenu = { open: open, close: close };
})(window, document);
