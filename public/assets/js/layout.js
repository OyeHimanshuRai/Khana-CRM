/*
|------------------------------------------------------------------------------
| Application shell behaviour
|------------------------------------------------------------------------------
| Sidebar collapse (desktop) and drawer (mobile), submenu accordions,
| dropdown menus, and the light/dark theme toggle.
|
| State lives in classes on <body> so CSS owns all the presentation:
|   .sidebar-collapsed   desktop rail mode   - persisted
|   .sidebar-open        mobile drawer open  - never persisted
|
| The theme is applied by an inline <head> script before first paint; this
| file only handles switching it afterwards.
*/
(function (window, document) {
    'use strict';

    var DESKTOP = 992;
    var KEY_SIDEBAR = 'erp.sidebar.collapsed';
    var KEY_THEME = 'erp.theme';

    var body = document.body;
    var root = document.documentElement;

    function isDesktop() {
        return window.innerWidth >= DESKTOP;
    }

    function store(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            /* private mode - a non-persisted session is fine */
        }
    }

    /* ------------------------------------------------------------ theme */

    function currentTheme() {
        try {
            return window.localStorage.getItem(KEY_THEME) || 'system';
        } catch (e) {
            return 'system';
        }
    }

    function applyTheme(theme) {
        if (theme === 'system') {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', theme);
        }

        // Keep every toggle in the page in sync with the active state.
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-theme-toggle]'),
            function (el) {
                var dark = theme === 'dark' ||
                    (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                el.setAttribute('aria-pressed', String(dark));
                el.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');

                var sun = el.querySelector('[data-icon-sun]');
                var moon = el.querySelector('[data-icon-moon]');
                if (sun) { sun.style.display = dark ? 'block' : 'none'; }
                if (moon) { moon.style.display = dark ? 'none' : 'block'; }
            }
        );
    }

    function toggleTheme() {
        var dark = root.getAttribute('data-theme') === 'dark' ||
            (!root.hasAttribute('data-theme') &&
                window.matchMedia('(prefers-color-scheme: dark)').matches);

        var next = dark ? 'light' : 'dark';
        store(KEY_THEME, next);
        applyTheme(next);
    }

    /* ---------------------------------------------------------- sidebar */

    function openDrawer() {
        body.classList.add('sidebar-open');
        var first = document.querySelector('.sidebar .nav-link');
        if (first) { first.focus({ preventScroll: true }); }
    }

    function closeDrawer() {
        body.classList.remove('sidebar-open');
    }

    function toggleSidebar() {
        if (isDesktop()) {
            var collapsed = body.classList.toggle('sidebar-collapsed');
            store(KEY_SIDEBAR, collapsed ? '1' : '0');
            return;
        }

        if (body.classList.contains('sidebar-open')) {
            closeDrawer();
        } else {
            openDrawer();
        }
    }

    /* --------------------------------------------------------- submenus */

    function collapseSub(item) {
        var sub = item.querySelector(':scope > .nav-sub');
        if (!sub) { return; }

        // Animate from the measured height rather than `auto`, which
        // cannot be transitioned.
        sub.style.height = sub.scrollHeight + 'px';
        void sub.offsetHeight;
        sub.style.height = '0px';

        item.classList.remove('is-expanded');
        var link = item.querySelector(':scope > .nav-link');
        if (link) { link.setAttribute('aria-expanded', 'false'); }
    }

    function expandSub(item) {
        var sub = item.querySelector(':scope > .nav-sub');
        if (!sub) { return; }

        item.classList.add('is-expanded');
        var link = item.querySelector(':scope > .nav-link');
        if (link) { link.setAttribute('aria-expanded', 'true'); }

        sub.style.height = sub.scrollHeight + 'px';

        // Release to `auto` so nested content can grow later.
        sub.addEventListener('transitionend', function once(e) {
            if (e.propertyName !== 'height') { return; }
            sub.removeEventListener('transitionend', once);
            if (item.classList.contains('is-expanded')) {
                sub.style.height = 'auto';
            }
        });
    }

    function toggleSub(item) {
        if (item.classList.contains('is-expanded')) {
            collapseSub(item);
            return;
        }

        // Accordion: only one group open per level.
        var siblings = item.parentNode.children;
        Array.prototype.forEach.call(siblings, function (sib) {
            if (sib !== item && sib.classList.contains('is-expanded')) {
                collapseSub(sib);
            }
        });

        expandSub(item);
    }

    /* -------------------------------------------------------- dropdowns */

    function closeDropdowns(except) {
        Array.prototype.forEach.call(
            document.querySelectorAll('.dropdown.is-open'),
            function (d) {
                if (d === except) { return; }
                d.classList.remove('is-open');
                unpin(d.querySelector('.dropdown-menu'));
                var trigger = d.querySelector('[data-dropdown-toggle]');
                if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            }
        );
    }

    /** Is this menu inside something that would clip it? */
    function isClipped(el) {
        for (var node = el.parentElement; node && node !== document.body; node = node.parentElement) {
            var overflow = window.getComputedStyle(node).overflow +
                window.getComputedStyle(node).overflowX +
                window.getComputedStyle(node).overflowY;

            if (/auto|scroll|hidden/.test(overflow)) {
                return true;
            }
        }

        return false;
    }

    /*
     | Row action menus live inside .table-wrap, which scrolls horizontally
     | and therefore clips them. Rather than let the menu be cut off, pin it
     | to the viewport under its trigger for as long as it is open.
     */
    function pin(menu, trigger) {
        if (!menu || !isClipped(menu)) { return; }

        var rect = trigger.getBoundingClientRect();

        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 8) + 'px';
        menu.style.right = 'auto';
        menu.style.left = 'auto';
        menu.dataset.pinned = '1';

        // Measure after placing, so a menu near the right or bottom edge is
        // pulled back inside rather than opening off-screen.
        var box = menu.getBoundingClientRect();
        var left = Math.min(rect.right - box.width, window.innerWidth - box.width - 8);

        menu.style.left = Math.max(8, left) + 'px';

        if (box.bottom > window.innerHeight - 8) {
            menu.style.top = Math.max(8, rect.top - box.height - 8) + 'px';
        }
    }

    function unpin(menu) {
        if (!menu || menu.dataset.pinned !== '1') { return; }

        menu.style.position = '';
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        delete menu.dataset.pinned;
    }

    /*
     | Dropdowns whose contents are fetched the first time they are opened.
     |
     | The notification bell is the one that needs this: rendering ten alert
     | rows into the header of every page would cost a query on screens that
     | never look at them, and a bell that is always empty until you reload
     | is worse than one that takes a moment.
     |
     | Fetched once per page. A stale list is the correct trade: the count on
     | the bell is server-rendered and already tells the truth, and re-fetching
     | on every open would hammer the endpoint for a menu that is mostly
     | opened by accident.
     */
    function loadFragment(dd) {
        var url = dd.getAttribute('data-dropdown-fragment');
        var menu = dd.querySelector('.dropdown-menu');

        if (!url || !menu || dd.dataset.fragmentLoaded === '1') { return; }

        dd.dataset.fragmentLoaded = '1';

        window.fetch(url, {
            headers: { 'X-Fragment': '1', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) { throw new Error(String(response.status)); }
                return response.text();
            })
            .then(function (html) {
                menu.innerHTML = html;
            })
            .catch(function () {
                // Let the next open try again rather than leaving "Loading…"
                // on screen for the rest of the session.
                dd.dataset.fragmentLoaded = '0';
                menu.innerHTML = '<div class="empty" style="padding:26px 14px">'
                    + '<div class="text-sm">Could not load notifications.</div></div>';
            });
    }

    /* ------------------------------------------------------------ wiring */

    document.addEventListener('click', function (event) {
        var themeBtn = event.target.closest('[data-theme-toggle]');
        if (themeBtn) {
            event.preventDefault();
            toggleTheme();
            return;
        }

        var sidebarBtn = event.target.closest('[data-sidebar-toggle]');
        if (sidebarBtn) {
            event.preventDefault();
            toggleSidebar();
            return;
        }

        if (event.target.closest('[data-sidebar-close]')) {
            event.preventDefault();
            closeDrawer();
            return;
        }

        var subToggle = event.target.closest('[data-submenu-toggle]');
        if (subToggle) {
            event.preventDefault();
            // In rail mode there is nowhere to expand into.
            if (isDesktop() && body.classList.contains('sidebar-collapsed')) {
                body.classList.remove('sidebar-collapsed');
                store(KEY_SIDEBAR, '0');
            }
            toggleSub(subToggle.closest('.nav-item'));
            return;
        }

        var dropToggle = event.target.closest('[data-dropdown-toggle]');
        if (dropToggle) {
            event.preventDefault();
            var dd = dropToggle.closest('.dropdown');
            closeDropdowns(dd);
            var open = dd.classList.toggle('is-open');
            dropToggle.setAttribute('aria-expanded', String(open));

            var menu = dd.querySelector('.dropdown-menu');
            if (open) {
                loadFragment(dd);
                pin(menu, dropToggle);
            } else {
                unpin(menu);
            }
            return;
        }

        // Placeholder menu entries: say so instead of navigating nowhere.
        var soon = event.target.closest('[data-soon]');
        if (soon) {
            event.preventDefault();
            var label = soon.getAttribute('data-label') || 'This module';
            if (window.Toast) {
                window.Toast.info(label + ' has not been built yet.');
            }
            if (!isDesktop()) { closeDrawer(); }
            return;
        }

        if (!event.target.closest('.dropdown-menu')) {
            closeDropdowns(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }

        closeDropdowns(null);

        if (body.classList.contains('sidebar-open')) {
            closeDrawer();
            var toggle = document.querySelector('[data-sidebar-toggle]');
            if (toggle) { toggle.focus(); }
        }
    });

    document.addEventListener('click', function (event) {
        // Tapping a real link inside the mobile drawer should dismiss it.
        if (isDesktop()) { return; }
        var link = event.target.closest('.sidebar a[href]');
        if (link && !link.hasAttribute('data-submenu-toggle')) {
            closeDrawer();
        }
    });

    /* -------------------------------------------------- header identity */

    /**
     * Repaint one [data-avatar] box as either a picture or initials.
     *
     * Reuses an existing <img> when there is one, so replacing a picture
     * swaps the src instead of tearing the element down and back up.
     */
    function paintAvatar(el, url, initials) {
        if (!url) {
            el.textContent = initials || '';
            return;
        }

        var img = el.querySelector('img');

        if (!img) {
            el.textContent = '';
            img = document.createElement('img');
            img.alt = '';
            el.appendChild(img);
        }

        img.src = url;
    }

    /*
     | The header renders the signed-in user server-side, so saving the
     | profile over AJAX - from its own page or the modal - would otherwise
     | leave a stale name and picture in the chrome until the next full
     | page load.
     |
     | Driven by the `data` block ProfileController returns on every save.
     */
    document.addEventListener('ajax:success', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-profile-form]')) { return; }

        var identity = event.detail && event.detail.data;
        if (!identity || !identity.name) { return; }

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-avatar]'),
            function (el) { paintAvatar(el, identity.avatar, identity.initials); }
        );

        Array.prototype.forEach.call(
            document.querySelectorAll('.header-user-meta strong'),
            function (el) { el.textContent = identity.name; }
        );

        // The fragment is not re-fetched after an upload, so Remove has to be
        // shown or hidden here to match what the account now has.
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-avatar-remove]'),
            function (el) { el.hidden = !identity.avatar; }
        );

        // A replaced picture leaves the old file selected, which would upload
        // it again on a second submit.
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-avatar-input]'),
            function (el) { el.value = ''; }
        );

        var head = document.querySelector('.header-user + .dropdown-menu .dropdown-header');
        if (head) {
            head.querySelector('strong').textContent = identity.name;
            head.querySelector('.text-xs').textContent = identity.email;
        }

        // Only the standalone profile page subtitles with the email; in a
        // modal, .page-sub belongs to whatever page is underneath.
        if (!form.closest('[data-modal-root]')) {
            var sub = document.querySelector('.page-sub');
            if (sub) { sub.textContent = identity.email; }
        }
    });

    /*
     | Show the chosen file straight away, so the picture is confirmed before
     | anything is uploaded. Scoped to the picker's own .avatar-upload block,
     | so the header avatar is left alone until the upload actually succeeds.
     */
    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-avatar-input]');
        if (!input) { return; }

        var file = input.files && input.files[0];
        var box = input.closest('.avatar-upload');
        var target = box && box.querySelector('[data-avatar]');
        if (!file || !target) { return; }

        var preview = window.URL.createObjectURL(file);
        paintAvatar(target, preview, '');

        var img = target.querySelector('img');
        if (img) {
            img.addEventListener('load', function () {
                window.URL.revokeObjectURL(preview);
            }, { once: true });
        }
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            // Crossing the breakpoint with the drawer open would leave the
            // page scroll-locked behind a now-docked sidebar.
            if (isDesktop()) { closeDrawer(); }
        }, 120);
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (currentTheme() === 'system') { applyTheme('system'); }
    });

    function init() {
        applyTheme(currentTheme());

        // Open the group containing the active page.
        var active = document.querySelector('.nav-sub .nav-link.is-active');
        if (active) {
            var item = active.closest('.nav-item.has-sub');
            if (item) {
                item.classList.add('is-expanded');
                var sub = item.querySelector(':scope > .nav-sub');
                var link = item.querySelector(':scope > .nav-link');
                if (sub) { sub.style.height = 'auto'; }
                if (link) { link.setAttribute('aria-expanded', 'true'); }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.AppShell = {
        toggleSidebar: toggleSidebar,
        closeDrawer: closeDrawer,
        // Used by modal.js: a trigger inside a menu leaves it open behind
        // the dialog, since the menu only closes on an outside click.
        closeMenus: function () { closeDropdowns(null); },
        applyTheme: applyTheme
    };
})(window, document);
