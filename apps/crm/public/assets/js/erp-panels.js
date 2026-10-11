/*
|------------------------------------------------------------------------------
| Panels that show what ERP knows
|------------------------------------------------------------------------------
|
| Two small jobs, both about the same thing: an outlet that has ERP sees a
| little of it inside CRM, and none of that may hold a CRM screen up.
|
|   1. Any element marked [data-erp-panel="<url>"] is filled from that URL
|      once it is on the page. The server draws the page without asking ERP
|      anything; the panel is fetched afterwards, so a slow or absent ERP
|      costs one card its contents and the rest of the screen is already
|      working. The URL is one of CRM's own - see ErpPanelController - which
|      answers with a fragment, including the one that says ERP is not
|      reachable right now.
|
|   2. On the register's paid-out card, picking one of ERP's unpaid supplier
|      bills fills in the rest: the cash is for stock bought, and the amount
|      is what is still due. Both stay editable, because a part payment is
|      normal.
|
| Markup contract:
|
|   <div data-erp-panel="/admin/products/12/erp-stock"> …placeholder… </div>
|
|   <form data-paid-out-form>
|     <select data-paid-out-bill>
|       <option value="">…</option>
|       <option value="<bill id>" data-due="1250.50"
|               data-supplier="Fresh Farms" data-number="FF/102">…</option>
|     </select>
|     <input type="hidden" data-paid-out-supplier>
|     <input type="hidden" data-paid-out-number>
|     <input data-paid-out-amount>
|     <select data-paid-out-category>
|     <input data-paid-out-note>
|   </form>
|
| Loaded globally rather than beside the panels: the menu item's page is
| injected into a modal, and a <script> that arrives that way never runs.
| Everything is delegated from document for the same reason.
|
| Depends on: nothing.
*/
(function (window, document) {
    'use strict';

    /** What a bill payment is filed as. Mirrors CashPaidOut::SUPPLIER_PAYMENT. */
    var SUPPLIER_PAYMENT = 'supplier_payment';

    /* ------------------------------------------------------------ panels */

    /**
     * Said when CRM itself did not answer - a dropped connection, a session
     * that has ended. Not the ERP sentence: that one comes from the server,
     * which is the only side that knows whether ERP was asked.
     */
    function failed(panel) {
        var note = document.createElement('p');

        note.className = 'text-sm text-muted';
        note.style.margin = '0';
        note.textContent = 'This could not be loaded. Reload the page to try again.';

        panel.innerHTML = '';
        panel.appendChild(note);
    }

    function fill(panel) {
        var url = panel.getAttribute('data-erp-panel');

        // Once per element. The observer below sees this panel's own new
        // contents arrive, and must not take that for a second panel.
        if (!url || panel.getAttribute('data-erp-panel-state')) {
            return;
        }

        panel.setAttribute('data-erp-panel-state', 'loading');
        panel.setAttribute('aria-busy', 'true');

        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Fragment': '1', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }

            return response.text();
        }).then(function (html) {
            // Our own fragment, escaped by Blade where it was built.
            panel.innerHTML = html;
            panel.setAttribute('data-erp-panel-state', 'loaded');
            panel.removeAttribute('aria-busy');
        }).catch(function () {
            failed(panel);
            panel.setAttribute('data-erp-panel-state', 'failed');
            panel.removeAttribute('aria-busy');
        });
    }

    function fillAll(root) {
        if (!root || !root.querySelectorAll) { return; }

        if (root.matches && root.matches('[data-erp-panel]')) { fill(root); }

        Array.prototype.forEach.call(root.querySelectorAll('[data-erp-panel]'), fill);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { fillAll(document); });
    } else {
        fillAll(document);
    }

    /*
     | Panels arrive after load too: the menu item's page is put into a modal
     | long after this file ran.
     */
    if (window.MutationObserver) {
        new window.MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) { fillAll(node); }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /* --------------------------------------------------- picking a bill */

    function part(form, name) {
        return form.querySelector('[data-paid-out-' + name + ']');
    }

    function set(field, value) {
        if (field) { field.value = value; }
    }

    /**
     * Carry the picked bill into the rest of the form - or, when the pick is
     * cleared, take back only what the pick put there.
     */
    function billPicked(form, select) {
        var option = select.options[select.selectedIndex];
        var picked = !!(option && option.value);
        var note = part(form, 'note');

        set(part(form, 'supplier'), picked ? (option.getAttribute('data-supplier') || '') : '');
        set(part(form, 'number'), picked ? (option.getAttribute('data-number') || '') : '');

        if (note) {
            // The bill says what the cash was for, so the note becomes
            // optional. Without a bill it is the only record of that.
            note.required = !picked;

            if (!note.hasAttribute('data-paid-out-placeholder')) {
                note.setAttribute('data-paid-out-placeholder', note.getAttribute('placeholder') || '');
            }

            note.setAttribute('placeholder', picked
                ? 'Optional: the bill already says what this was for.'
                : note.getAttribute('data-paid-out-placeholder'));
        }

        if (!picked) {
            return;
        }

        set(part(form, 'category'), SUPPLIER_PAYMENT);

        var due = parseFloat(option.getAttribute('data-due'));

        // What is still due, as a starting point. A bill ERP lists with
        // nothing due leaves the amount for the cashier to type.
        if (isFinite(due) && due > 0) {
            set(part(form, 'amount'), due.toFixed(2));
        }
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        var form = target.closest ? target.closest('[data-paid-out-form]') : null;

        if (!form) {
            return;
        }

        if (target.hasAttribute('data-paid-out-bill')) {
            billPicked(form, target);

            return;
        }

        /*
         | Calling it something other than stock bought un-picks the bill. A
         | paid-out that names a bill is filed against that bill whatever
         | else it says, and a form showing "Gas" beside a supplier's bill
         | would be promising something else.
         */
        if (target.hasAttribute('data-paid-out-category') && target.value !== SUPPLIER_PAYMENT) {
            var bill = part(form, 'bill');

            if (bill && bill.value) {
                bill.value = '';
                billPicked(form, bill);
            }
        }
    });
})(window, document);
