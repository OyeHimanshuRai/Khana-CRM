/*
|------------------------------------------------------------------------------
| AJAX listings
|------------------------------------------------------------------------------
| Turns any server-rendered listing into a no-reload one. Filters, search,
| sorting, page size and pagination all fetch a fragment and swap it in.
|
| Markup contract:
|
|   <div data-ajax-list="{{ route('admin.users.index') }}">
|       ...controls marked [data-ajax-filter] (must have a name)...
|       <div data-ajax-list-content>
|           @include('admin.users._list')   <-- table + <x-pagination />
|       </div>
|   </div>
|
| The server returns just that fragment when the request carries
| `X-Fragment: 1`. Page links inside the fragment carry data-ajax-page.
|
| A fragment may also refresh things drawn outside the listing - summary
| tiles above the card, typically - by carrying:
|
|   <template data-ajax-oob="[data-kds-counts]"> …the new tiles… </template>
|
| whose contents replace whatever that selector matches on the page.
|
| Progressive enhancement: without JavaScript the filters are plain forms and
| the page links are plain links, so the listing still works - this file only
| intercepts what it can handle.
*/
(function (window, document) {
    'use strict';

    var SEARCH_DEBOUNCE = 350;

    function contentOf(list) {
        return list.querySelector('[data-ajax-list-content]');
    }

    /**
     * Gather every filter control into a query string.
     * Blank values are omitted so the URL stays readable.
     */
    function collect(list) {
        var params = new URLSearchParams();

        list.querySelectorAll('[data-ajax-filter]').forEach(function (el) {
            if (!el.name || el.disabled) {
                return;
            }

            if (el.type === 'checkbox') {
                if (el.checked) { params.set(el.name, el.value || '1'); }
                return;
            }

            var value = (el.value || '').trim();
            if (value !== '') { params.set(el.name, value); }
        });

        return params;
    }

    function baseUrl(list) {
        return list.dataset.ajaxList || window.location.pathname;
    }

    /**
     * Update the parts of the page that live outside the listing.
     *
     * A fragment can carry <template data-ajax-oob="<selector>"> and its
     * contents replace whatever that selector matches on the page. The
     * template is then removed, so nothing is left behind and a second swap
     * cannot pick up the first one's markup.
     *
     * This exists for summary tiles. The counts above a kitchen display or a
     * day's bookings are computed from the very rows in the fragment, but
     * they are drawn above the card - outside [data-ajax-list-content] - so
     * every refresh updated the rows underneath and left the tiles frozen at
     * whatever the page happened to load with. On a screen that stays open
     * for a whole shift those numbers drift further from the truth all day,
     * which is worse than showing none: nobody doubts a tile.
     */
    function swapOutOfBand(content) {
        content.querySelectorAll('template[data-ajax-oob]').forEach(function (template) {
            var target = document.querySelector(template.getAttribute('data-ajax-oob'));

            if (target) {
                target.innerHTML = template.innerHTML;
            }

            template.remove();
        });
    }

    function load(list, params, options) {
        options = options || {};

        var content = contentOf(list);
        if (!content) { return; }

        var query = params.toString();
        var url = baseUrl(list) + (query ? '?' + query : '');

        // Supersede any request still in flight, so a fast typist never sees
        // an older response land after a newer one.
        if (list._ajaxAbort) { list._ajaxAbort.abort(); }
        var controller = new AbortController();
        list._ajaxAbort = controller;

        content.classList.add('is-loading');
        content.setAttribute('aria-busy', 'true');

        fetch(url, {
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                'X-Fragment': '1',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.text();
        }).then(function (html) {
            content.innerHTML = html;
            swapOutOfBand(content);
            content.classList.remove('is-loading');
            content.removeAttribute('aria-busy');
            list._ajaxAbort = null;

            // Selection state belongs to rows that no longer exist.
            if (window.BulkSelect) {
                list.querySelectorAll('[data-bulk-scope]').forEach(window.BulkSelect.refresh);
                if (list.matches('[data-bulk-scope]')) { window.BulkSelect.refresh(list); }
            }

            if (options.push !== false) {
                window.history.pushState({ ajaxList: true }, '', url);
            }

            if (options.scroll) {
                list.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            list.dispatchEvent(new CustomEvent('ajaxlist:loaded', {
                bubbles: true,
                detail: { url: url }
            }));
        }).catch(function (error) {
            if (error.name === 'AbortError') { return; }

            content.classList.remove('is-loading');
            content.removeAttribute('aria-busy');

            if (window.Toast) {
                window.Toast.error('Could not load the list. Please try again.');
            }
        });
    }

    /* ------------------------------------------------------------ events */

    // Selects and checkboxes: apply immediately.
    document.addEventListener('change', function (event) {
        var el = event.target.closest('[data-ajax-filter]');
        if (!el || el.type === 'search' || el.type === 'text') { return; }

        var list = el.closest('[data-ajax-list]');
        if (!list) { return; }

        event.preventDefault();

        var params = collect(list);
        // Any filter change invalidates the current page number.
        if (el.name !== 'page') { params.delete('page'); }

        load(list, params);
    });

    // Free-text boxes: debounce so each keystroke is not a request.
    document.addEventListener('input', function (event) {
        var el = event.target.closest('[data-ajax-filter]');
        if (!el || (el.type !== 'search' && el.type !== 'text')) { return; }

        var list = el.closest('[data-ajax-list]');
        if (!list) { return; }

        window.clearTimeout(list._ajaxTimer);
        list._ajaxTimer = window.setTimeout(function () {
            var params = collect(list);
            params.delete('page');
            load(list, params);
        }, SEARCH_DEBOUNCE);
    });

    // Enter inside a filter box should not submit the wrapping form.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') { return; }

        var el = event.target.closest('[data-ajax-filter]');
        if (!el || !el.closest('[data-ajax-list]')) { return; }

        event.preventDefault();

        var list = el.closest('[data-ajax-list]');
        window.clearTimeout(list._ajaxTimer);

        var params = collect(list);
        params.delete('page');
        load(list, params);
    });

    // Pagination links.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-ajax-page]');
        if (!link) { return; }

        var list = link.closest('[data-ajax-list]');
        if (!list) { return; }

        event.preventDefault();

        var params = collect(list);
        params.set('page', link.getAttribute('data-ajax-page'));

        load(list, params, { scroll: true });
    });

    // Reset link: clear everything and reload the bare listing.
    document.addEventListener('click', function (event) {
        var reset = event.target.closest('[data-ajax-reset]');
        if (!reset) { return; }

        var list = reset.closest('[data-ajax-list]');
        if (!list) { return; }

        event.preventDefault();

        list.querySelectorAll('[data-ajax-filter]').forEach(function (el) {
            if (el.type === 'checkbox') { el.checked = false; return; }
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; return; }
            if (el.name === 'per_page') { return; }
            el.value = '';
        });

        load(list, collect(list));
    });

    // Back/forward through the pushed history entries.
    window.addEventListener('popstate', function (event) {
        if (!event.state || !event.state.ajaxList) { return; }

        var list = document.querySelector('[data-ajax-list]');
        if (!list) { return; }

        var params = new URLSearchParams(window.location.search);
        load(list, params, { push: false });
    });

    window.AjaxList = {
        reload: function (list) {
            var el = typeof list === 'string' ? document.querySelector(list) : list;
            if (el) { load(el, collect(el), { push: false }); }
        }
    };
})(window, document);
