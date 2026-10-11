/*
|------------------------------------------------------------------------------
| Tabs
|------------------------------------------------------------------------------
|
| Markup:
|
|   <div class="tabs" role="tablist" data-tabs="settings">
|     <button type="button" class="tab" role="tab" data-tab="company">…</button>
|   </div>
|   <div data-tab-panels="settings">
|     <div class="tab-panel" role="tabpanel" data-tab-panel="company">…</div>
|   </div>
|
| The data-tabs value names the group; the matching data-tab-panels holds
| its panels. Several groups can coexist on one page.
|
| Which tab is open survives a reload two ways: the URL hash (#company),
| which also makes tabs linkable, and localStorage as the fallback.
|
| Panels are hidden by CSS only once this file marks the group ready, so
| with JavaScript off the page degrades to every panel stacked - see the
| [data-tabs-ready] rules in theme.css.
|
| Events on the tab list (bubbles):
|   tabs:changed   detail = { group, tab }
|
| Depends on: public/assets/css/theme.css (.tab*)
*/
(function (window, document) {
    'use strict';

    var STORE_PREFIX = 'erp.tabs.';

    function group(name) {
        return {
            list: document.querySelector('[data-tabs="' + name + '"]'),
            panels: document.querySelector('[data-tab-panels="' + name + '"]')
        };
    }

    function tabsIn(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[data-tab]'));
    }

    function store(name, tab) {
        try {
            window.localStorage.setItem(STORE_PREFIX + name, tab);
        } catch (e) {
            /* private mode - the hash still carries it within the session */
        }
    }

    function stored(name) {
        try {
            return window.localStorage.getItem(STORE_PREFIX + name);
        } catch (e) {
            return null;
        }
    }

    /* ----------------------------------------------------- overflow ui */

    /**
     * Wrap a tab list in the scroll chrome, once.
     *
     * Built here rather than asked for in the markup, so every page that
     * uses .tabs gets the arrows and edge fades for free.
     */
    function chrome(list) {
        var wrap = list.parentNode;

        if (wrap && wrap.classList.contains('tabs-wrap')) {
            return wrap;
        }

        wrap = document.createElement('div');
        wrap.className = 'tabs-wrap';
        list.parentNode.insertBefore(wrap, list);
        wrap.appendChild(list);

        ['prev', 'next'].forEach(function (side) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tabs-nav is-' + side;
            button.setAttribute('data-tabs-scroll', side);
            button.setAttribute('aria-label', side === 'prev' ? 'Scroll tabs left' : 'Scroll tabs right');
            button.setAttribute('tabindex', '-1');
            button.hidden = true;
            button.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" ' +
                'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
                'stroke-linejoin="round" aria-hidden="true"><polyline points="' +
                (side === 'prev' ? '15 18 9 12 15 6' : '9 18 15 12 9 6') + '"/></svg>';

            wrap.appendChild(button);
        });

        return wrap;
    }

    /** Show or hide the arrows and edge fades for one list. */
    function refresh(list) {
        var wrap = list.parentNode;
        if (!wrap || !wrap.classList.contains('tabs-wrap')) { return; }

        // A sub-pixel slack, or a fully scrolled row can still report a
        // 0.5px remainder and keep an arrow on screen for ever.
        var max = list.scrollWidth - list.clientWidth;
        var start = list.scrollLeft > 1;
        var end = list.scrollLeft < max - 1;

        wrap.classList.toggle('can-scroll-start', start);
        wrap.classList.toggle('can-scroll-end', end);

        var prev = wrap.querySelector('[data-tabs-scroll="prev"]');
        var next = wrap.querySelector('[data-tabs-scroll="next"]');
        if (prev) { prev.hidden = !start; }
        if (next) { next.hidden = !end; }
    }

    /** Scroll a tab fully into view, clear of the arrows sitting over it. */
    function reveal(list, tab) {
        if (!tab) { return; }

        // Measured from rects rather than offsetLeft, which depends on which
        // ancestor happens to be positioned.
        var listRect = list.getBoundingClientRect();
        var tabRect = tab.getBoundingClientRect();

        var pad = 40;
        var left = tabRect.left - listRect.left + list.scrollLeft;
        var right = left + tabRect.width;

        if (left - pad < list.scrollLeft) {
            list.scrollLeft = Math.max(0, left - pad);
        } else if (right + pad > list.scrollLeft + list.clientWidth) {
            list.scrollLeft = right + pad - list.clientWidth;
        }

        refresh(list);
    }

    /**
     * Open one tab in a group.
     *
     * @param {string}  name     group name
     * @param {string}  tab      the [data-tab] value to open
     * @param {object}  options  { focus, hash } - both default to false
     */
    function activate(name, tab, options) {
        options = options || {};

        var parts = group(name);
        if (!parts.list || !parts.panels) { return; }

        var found = false;

        tabsIn(parts.list).forEach(function (button) {
            var on = button.getAttribute('data-tab') === tab;
            if (on) { found = true; }

            button.classList.toggle('is-active', on);
            button.setAttribute('aria-selected', String(on));
            // Only the active tab is in the tab order; arrows move between them.
            button.setAttribute('tabindex', on ? '0' : '-1');

            if (on && options.focus) { button.focus(); }
        });

        if (!found) { return; }

        Array.prototype.forEach.call(
            parts.panels.querySelectorAll('[data-tab-panel]'),
            function (panel) {
                var on = panel.getAttribute('data-tab-panel') === tab;
                panel.classList.toggle('is-active', on);
                panel.hidden = !on;
            }
        );

        // With ten tabs the active one is often off-screen - on a reload
        // deep-linked to #integrations, most of all.
        reveal(parts.list, parts.list.querySelector('[data-tab="' + tab + '"]'));

        store(name, tab);

        if (options.hash) {
            // replaceState, not a hash assignment: changing location.hash
            // would jump the scroll position to the panel.
            window.history.replaceState(null, '', '#' + tab);
        }

        parts.list.dispatchEvent(new CustomEvent('tabs:changed', {
            bubbles: true,
            detail: { group: name, tab: tab }
        }));
    }

    /** First tab whose value exists in the group, else the first tab. */
    function resolve(list, candidates) {
        var available = tabsIn(list).map(function (b) { return b.getAttribute('data-tab'); });

        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i] && available.indexOf(candidates[i]) !== -1) {
                return candidates[i];
            }
        }

        return available[0] || null;
    }

    /* ------------------------------------------------------------ wiring */

    document.addEventListener('click', function (event) {
        var arrow = event.target.closest('[data-tabs-scroll]');
        if (arrow) {
            event.preventDefault();

            var scroller = arrow.parentNode.querySelector('[data-tabs]');
            if (!scroller) { return; }

            // Most of a screenful, keeping a little of the old edge visible
            // so the jump stays orientating.
            var step = Math.max(120, scroller.clientWidth * 0.7);
            scroller.scrollLeft += arrow.getAttribute('data-tabs-scroll') === 'prev' ? -step : step;
            return;
        }

        var button = event.target.closest('[data-tab]');
        if (!button) { return; }

        var list = button.closest('[data-tabs]');
        if (!list) { return; }

        event.preventDefault();

        activate(list.getAttribute('data-tabs'), button.getAttribute('data-tab'), { hash: true });
    });

    document.addEventListener('keydown', function (event) {
        var button = event.target.closest('[data-tab]');
        if (!button) { return; }

        var list = button.closest('[data-tabs]');
        if (!list) { return; }

        var keys = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
        if (!(event.key in keys)) { return; }

        event.preventDefault();

        var buttons = tabsIn(list);
        var index = buttons.indexOf(button);
        var next;

        if (keys[event.key] === 'first') {
            next = 0;
        } else if (keys[event.key] === 'last') {
            next = buttons.length - 1;
        } else {
            next = (index + keys[event.key] + buttons.length) % buttons.length;
        }

        activate(
            list.getAttribute('data-tabs'),
            buttons[next].getAttribute('data-tab'),
            { focus: true, hash: true }
        );
    });

    var resizeTimer = null;

    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            Array.prototype.forEach.call(document.querySelectorAll('[data-tabs]'), refresh);
        }, 120);
    });

    function init() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-tabs]'),
            function (list) {
                var name = list.getAttribute('data-tabs');
                var parts = group(name);
                if (!parts.panels) { return; }

                // Marks the group as JS-driven, which is what lets the CSS
                // start hiding panels.
                parts.panels.setAttribute('data-tabs-ready', '');

                chrome(list);
                list.addEventListener('scroll', function () { refresh(list); }, { passive: true });

                var hash = window.location.hash.replace('#', '');
                var start = resolve(list, [hash, stored(name)]);

                if (start) {
                    activate(name, start, { hash: false });
                }

                refresh(list);
            }
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.Tabs = { activate: activate };
})(window, document);
