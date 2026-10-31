/*
|------------------------------------------------------------------------------
| Customer picker
|------------------------------------------------------------------------------
|
| The type-ahead that appears wherever a customer has to be chosen: the POS
| terminal, the payment form, the ledger filter. Written with delegated
| handlers on `document` rather than per-element wiring, because half its
| uses are inside modals - markup injected after page load, which anything
| bound at DOMContentLoaded would never see.
|
| Markup contract:
|
|   <div data-customer-picker data-lookup-url="/admin/customers/lookup">
|     <input data-customer-search>
|     <div data-customer-results hidden></div>
|     <input type="hidden" name="customer_id" data-customer-id>
|   </div>
|
|   <div data-customer-chosen hidden>      … sibling, anywhere in the form
|     <strong data-customer-name></strong>
|     <span data-customer-meta></span>
|     <button data-customer-clear></button>
|   </div>
|
| Events, both bubbling from the picker:
|   customer:chosen   detail = the customer row from the lookup
|   customer:cleared  detail = null
|
| The credit position travels with the row, so callers can warn about a sale
| the customer cannot take before the server has to refuse it.
|
| Depends on: public/assets/js/app.js (Toast)
*/
(function (window, document) {
    'use strict';

    var DEBOUNCE = 220;
    var timers = new WeakMap();

    function money(value) {
        return (Math.round(value * 100) / 100).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    /** The [data-customer-chosen] panel belonging to a picker. */
    function panelFor(picker) {
        var scope = picker.closest('form') || document;

        return scope.querySelector('[data-customer-chosen]');
    }

    function render(picker, rows) {
        var results = picker.querySelector('[data-customer-results]');

        if (!results) {
            return;
        }

        if (!rows.length) {
            results.innerHTML = '<div class="line-result is-empty">No customer matched.</div>';
            results.hidden = false;

            return;
        }

        results.innerHTML = rows.map(function (customer, index) {
            /*
             | What the counter needs at a glance is not the address - it is
             | whether this person can be sold to on credit, and how much
             | they already owe.
             */
            var right = customer.balance > 0
                ? '<span class="line-result-stock is-out">owes ₹' + money(customer.balance) + '</span>'
                : '<span class="line-result-stock">'
                    + (customer.allow_credit
                        ? '₹' + money(customer.available_credit) + ' credit'
                        : 'cash only')
                    + '</span>';

            return '<button type="button" class="line-result" data-customer-pick="' + index + '">'
                + '<span class="line-result-name">' + escapeHtml(customer.name) + '</span>'
                + '<span class="line-result-meta">'
                + escapeHtml(customer.mobile || customer.code || '') + '</span>'
                + right
                + '</button>';
        }).join('');

        results.hidden = false;
        results.__rows = rows;
    }

    function search(picker, term) {
        var results = picker.querySelector('[data-customer-results]');
        var url = picker.getAttribute('data-lookup-url');

        if (!results || !url) {
            return;
        }

        if (term.length < 1) {
            results.hidden = true;

            return;
        }

        var separator = url.indexOf('?') === -1 ? '?' : '&';

        window.fetch(url + separator + 'q=' + encodeURIComponent(term), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken()
            },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                render(picker, ((payload && payload.data) || {}).results || []);
            })
            .catch(function () {
                if (window.Toast) {
                    window.Toast.error('Could not reach the customer lookup.');
                }
            });
    }

    function choose(picker, customer) {
        var hidden = picker.querySelector('[data-customer-id]');
        var input = picker.querySelector('[data-customer-search]');
        var results = picker.querySelector('[data-customer-results]');
        var panel = panelFor(picker);

        if (hidden) { hidden.value = customer.id; }
        if (input) { input.value = ''; }
        if (results) { results.hidden = true; }

        picker.hidden = true;

        if (panel) {
            panel.hidden = false;

            var name = panel.querySelector('[data-customer-name]');
            var meta = panel.querySelector('[data-customer-meta]');

            if (name) { name.textContent = customer.name; }

            if (meta) {
                var parts = [];

                if (customer.mobile) { parts.push(customer.mobile); }
                if (customer.type) { parts.push(customer.type); }

                if (customer.balance > 0) {
                    parts.push('owes ₹' + money(customer.balance));
                }

                parts.push(customer.allow_credit
                    ? '₹' + money(customer.available_credit) + ' credit left'
                    : 'cash only');

                meta.textContent = parts.join(' · ');
            }
        }

        picker.__customer = customer;

        picker.dispatchEvent(new CustomEvent('customer:chosen', {
            bubbles: true,
            detail: customer
        }));
    }

    function clear(picker) {
        var hidden = picker.querySelector('[data-customer-id]');
        var input = picker.querySelector('[data-customer-search]');
        var panel = panelFor(picker);

        if (hidden) { hidden.value = ''; }

        picker.hidden = false;

        if (panel) { panel.hidden = true; }

        picker.__customer = null;

        if (input) { input.focus(); }

        picker.dispatchEvent(new CustomEvent('customer:cleared', {
            bubbles: true,
            detail: null
        }));
    }

    /* ------------------------------------------------------- delegation */

    document.addEventListener('input', function (event) {
        var input = event.target.closest('[data-customer-search]');

        if (!input) {
            return;
        }

        var picker = input.closest('[data-customer-picker]');

        window.clearTimeout(timers.get(input));

        var term = input.value.trim();
        timers.set(input, window.setTimeout(function () { search(picker, term); }, DEBOUNCE));
    });

    document.addEventListener('keydown', function (event) {
        var input = event.target.closest('[data-customer-search]');

        if (!input || event.key !== 'Enter') {
            return;
        }

        // Enter in a search box must never submit the surrounding form.
        event.preventDefault();

        var picker = input.closest('[data-customer-picker]');
        window.clearTimeout(timers.get(input));
        search(picker, input.value.trim());
    });

    document.addEventListener('click', function (event) {
        var pick = event.target.closest('[data-customer-pick]');

        if (pick) {
            var picker = pick.closest('[data-customer-picker]');
            var results = picker.querySelector('[data-customer-results]');
            var rows = (results && results.__rows) || [];
            var customer = rows[parseInt(pick.getAttribute('data-customer-pick'), 10)];

            if (customer) {
                choose(picker, customer);
            }

            return;
        }

        var clearButton = event.target.closest('[data-customer-clear]');

        if (clearButton) {
            var scope = clearButton.closest('form') || document;
            var target = scope.querySelector('[data-customer-picker]');

            if (target) {
                clear(target);
            }
        }
    });

    // Delayed so a click on a result still lands before the panel hides.
    document.addEventListener('focusout', function (event) {
        var input = event.target.closest('[data-customer-search]');

        if (!input) {
            return;
        }

        var picker = input.closest('[data-customer-picker]');

        window.setTimeout(function () {
            var results = picker.querySelector('[data-customer-results]');

            if (results) { results.hidden = true; }
        }, 160);
    });

    /* --------------------------------------------- payment-method fields */

    /*
     | A cheque is the one method that does not settle when it is taken, so
     | it is the one method with extra fields. Delegated for the same reason
     | as everything else here: the form lives in a modal.
     */
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-payment-method]');

        if (!select) {
            return;
        }

        var form = select.closest('form');
        var option = select.options[select.selectedIndex];
        var instant = option.getAttribute('data-instant') !== '0';

        form.querySelectorAll('[data-cheque-only]').forEach(function (field) {
            field.hidden = select.value !== 'cheque';
        });

        var hint = form.querySelector('[data-payment-method-hint]');

        if (hint) {
            hint.textContent = instant
                ? option.textContent.trim() + ' settles immediately.'
                : option.textContent.trim() + ' is recorded now but does not reduce the balance '
                    + 'until it is marked as cleared.';
        }
    });

    window.CustomerPicker = { choose: choose, clear: clear };
})(window, document);
