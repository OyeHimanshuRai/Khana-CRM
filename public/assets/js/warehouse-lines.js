/*
|------------------------------------------------------------------------------
| Warehouse-scoped line documents
|------------------------------------------------------------------------------
|
| Used by the stock adjustment and stock transfer forms - anything whose
| lines mean something only in relation to one warehouse. Two jobs that
| line-items.js cannot do generically:
|
|   1. The product lookup is scoped to the chosen warehouse. "How much is
|      there" has no answer until you say where, and a count sheet showing
|      shop-wide totals would be actively misleading.
|
|   2. Where the form has a [data-line-difference] cell, it is kept at
|      expected minus entered as the user types - so a mis-keyed quantity is
|      visible before it is saved rather than after it is approved. Forms
|      without that cell (a transfer, where the entered quantity is not a
|      correction) simply skip it.
|
| Changing the warehouse clears the lines. That is deliberate: the expected
| quantities already on screen belong to the old location, and silently
| re-pointing them at a new one would produce a document that says something
| nobody checked.
|
| Markup contract, on top of the one line-items.js defines:
|   [data-warehouse-select]   the <select> the lookup follows
|   [data-line-expected]      per-row cell holding the warehouse quantity
|   [data-line-difference]    per-row cell this file writes (optional)
|
| Depends on: public/assets/js/line-items.js
*/
(function (window, document) {
    'use strict';

    function num(value) {
        var parsed = parseFloat(value);

        return isNaN(parsed) ? 0 : parsed;
    }

    function trim(value) {
        var fixed = (Math.round(value * 1000) / 1000).toFixed(3);

        return fixed.replace(/\.?0+$/, '') || '0';
    }

    /** Point the lookup at the warehouse currently selected. */
    function retarget(container, select) {
        var base = container.getAttribute('data-lookup-base');

        if (!base) {
            base = container.getAttribute('data-lookup-url');
            container.setAttribute('data-lookup-base', base);
        }

        var separator = base.indexOf('?') === -1 ? '?' : '&';

        container.setAttribute('data-lookup-url', base + separator + 'warehouse=' + encodeURIComponent(select.value));
    }

    /** Expected minus counted, for one row. */
    function refreshRow(row) {
        var expectedCell = row.querySelector('[data-line-expected]');
        var differenceCell = row.querySelector('[data-line-difference]');
        var quantityInput = row.querySelector('[data-line-quantity]');

        if (!expectedCell || !differenceCell || !quantityInput) {
            return;
        }

        var expected = num(expectedCell.textContent);
        var counted = num(quantityInput.value);
        var difference = counted - expected;

        if (Math.abs(difference) < 0.0005) {
            differenceCell.innerHTML = '<span class="text-muted">no change</span>';

            return;
        }

        var colour = difference < 0 ? 'var(--danger)' : 'var(--success)';
        var sign = difference > 0 ? '+' : '−';

        differenceCell.innerHTML = '<strong style="color:' + colour + '">'
            + sign + trim(Math.abs(difference)) + '</strong>';
    }

    function refreshAll(container) {
        container.querySelectorAll('[data-line-row]').forEach(refreshRow);
    }

    function init(container) {
        var select = document.querySelector('[data-warehouse-select]');

        if (select) {
            retarget(container, select);

            select.addEventListener('change', function () {
                retarget(container, select);

                var body = container.querySelector('[data-line-body]');

                if (body && body.children.length) {
                    if (!window.confirm('Changing the warehouse clears the counted lines, because the expected quantities belong to the old location. Continue?')) {
                        // Put the old choice back; nothing has been lost.
                        select.value = select.getAttribute('data-previous') || select.value;

                        return;
                    }

                    body.innerHTML = '';

                    if (window.LineItems) {
                        window.LineItems.recalculate(container);
                    }
                }

                select.setAttribute('data-previous', select.value);
            });

            select.setAttribute('data-previous', select.value);
        }

        // line-items.js fires these after every add, remove and keystroke.
        ['line:added', 'line:changed', 'line:removed'].forEach(function (name) {
            container.addEventListener(name, function () { refreshAll(container); });
        });

        refreshAll(container);
    }

    function boot() {
        document.querySelectorAll('[data-line-items]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
