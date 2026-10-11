/*
|------------------------------------------------------------------------------
| Point of sale
|------------------------------------------------------------------------------
|
| The counter screen. line-items.js owns the cart itself - scanning, rows,
| quantities, per-line amounts. This file owns everything to the right of it:
|
|   the customer      type-ahead, and the credit position that comes with it
|   the bill discount kept consistent between the â‚¹ and % boxes
|   the tender        split payments, change due, what goes on account
|   the guard rails   warning before the server refuses, not after
|
| The arithmetic here is a preview. The server recomputes every figure from
| the products themselves, because a total the browser could set is a total
| nobody can trust. What this file is for is telling the cashier what will
| happen before they press the button.
|
| Prices at the counter always include GST - see PosController, which tells
| the invoice service exactly that. So the payable is simply the sum of the
| line amounts less the bill discount, and the tax split appears on the
| invoice rather than on this screen.
|
| Keyboard, because a counter is not a mouse:
|   F2         back to the scan box
|   F4         focus the first tender amount
|   Ctrl+Enter complete the sale
|
| Depends on: public/assets/js/line-items.js, app.js
*/
(function (window, document) {
    'use strict';

    var SEARCH_DEBOUNCE = 220;

    /* --------------------------------------------------------------- util */

    function num(value) {
        var parsed = parseFloat(value);

        return isNaN(parsed) ? 0 : parsed;
    }

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

    function writeAll(root, selector, text) {
        root.querySelectorAll(selector).forEach(function (element) {
            element.textContent = text;
        });
    }

    /* ----------------------------------------------------------- the till */

    function Pos(form) {
        this.form = form;
        this.cart = form.querySelector('[data-line-items]');
        this.canDiscount = form.getAttribute('data-can-discount') === '1';
        this.canCredit = form.getAttribute('data-can-credit') === '1';

        this.customer = null;
        this.tenderIndex = 0;

        this.wireCustomer();
        this.wireDiscount();
        this.wireTender();
        this.wireKeyboard();
        this.wireCompletion();

        // One tender row to start; the common sale is a single payment.
        this.addTender();
        this.recalculate();
    }


    /* ---------------------------------------------------------- customer */

    /*
     | The picker itself lives in customer-picker.js, which is delegated and
     | therefore works inside modals too. All the till needs is to know who
     | was chosen, because the credit warnings below depend on it.
     */
    Pos.prototype.wireCustomer = function () {
        var self = this;

        this.form.addEventListener('customer:chosen', function (event) {
            self.customer = event.detail;
            self.toggleWalkIn(false);
            self.recalculate();
        });

        this.form.addEventListener('customer:cleared', function () {
            self.customer = null;
            self.toggleWalkIn(true);
            self.recalculate();
        });
    };

    /** The "name on the bill" box only matters without an account. */
    Pos.prototype.toggleWalkIn = function (visible) {
        var walkIn = this.form.querySelector('[data-walkin]');

        if (walkIn) {
            walkIn.hidden = !visible;
        }
    };
    /* ---------------------------------------------------------- discount */

    Pos.prototype.wireDiscount = function () {
        var self = this;

        this.discountAmount = this.form.querySelector('[data-pos-discount-amount]');
        this.discountPercent = this.form.querySelector('[data-pos-discount-percent]');

        if (!this.discountAmount || !this.discountPercent) {
            return;
        }

        /*
         | The two boxes are one number wearing two hats. Typing in either
         | clears the other, because a bill carrying both a â‚¹ and a % figure
         | leaves the cashier guessing which one applied.
         */
        this.discountAmount.addEventListener('input', function () {
            if (num(self.discountAmount.value) > 0) {
                self.discountPercent.value = 0;
            }

            self.recalculate();
        });

        this.discountPercent.addEventListener('input', function () {
            if (num(self.discountPercent.value) > 0) {
                self.discountAmount.value = 0;
            }

            self.recalculate();
        });
    };

    /* ------------------------------------------------------------ tender */

    Pos.prototype.wireTender = function () {
        var self = this;

        this.tenderBody = this.form.querySelector('[data-tender-body]');
        this.tenderTemplate = this.form.querySelector('[data-tender-template]');

        var addButton = this.form.querySelector('[data-tender-add]');

        if (addButton) {
            addButton.addEventListener('click', function () { self.addTender(); });
        }

        this.form.addEventListener('input', function (event) {
            if (event.target.closest('[data-tender-amount]')) {
                self.recalculate();
            }
        });

        this.form.addEventListener('change', function (event) {
            var method = event.target.closest('[data-tender-method]');

            if (!method) {
                return;
            }

            // A cheque needs its number; nothing else does.
            var row = method.closest('[data-tender-row]');
            var extra = row.querySelector('[data-tender-extra]');

            if (extra) {
                extra.hidden = method.value !== 'cheque';
            }

            self.recalculate();
        });

        this.form.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-tender-remove]');

            if (!remove) {
                return;
            }

            var rows = self.tenderBody.querySelectorAll('[data-tender-row]');

            // Never leave the till with no way to take money.
            if (rows.length <= 1) {
                var only = rows[0].querySelector('[data-tender-amount]');
                only.value = '';
                self.recalculate();

                return;
            }

            remove.closest('[data-tender-row]').remove();
            self.renumberTenders();
            self.recalculate();
        });
    };

    Pos.prototype.addTender = function () {
        if (!this.tenderTemplate || !this.tenderBody) {
            return;
        }

        var html = this.tenderTemplate.innerHTML.replace(/\{\{index\}\}/g, this.tenderIndex++);
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();

        var row = wrapper.querySelector('[data-tender-row]');
        this.tenderBody.appendChild(row);

        this.renumberTenders();

        return row;
    };

    /** Keep payments[] a dense array whatever has been removed. */
    Pos.prototype.renumberTenders = function () {
        this.tenderBody.querySelectorAll('[data-tender-row]').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/payments\[\d*\]/, 'payments[' + index + ']');
            });
        });
    };

    Pos.prototype.tendered = function () {
        var total = 0;

        this.tenderBody.querySelectorAll('[data-tender-amount]').forEach(function (input) {
            total += num(input.value);
        });

        return total;
    };

    /* -------------------------------------------------------------- sums */

    Pos.prototype.recalculate = function () {
        var totals = (this.cart && this.cart.__lineTotals) || { value: 0 };
        var subtotal = totals.value || 0;

        var discount = 0;

        if (this.canDiscount && this.discountAmount) {
            var percent = num(this.discountPercent.value);

            discount = percent > 0
                ? subtotal * percent / 100
                : num(this.discountAmount.value);

            discount = Math.min(Math.max(discount, 0), subtotal);
        }

        // The counter bills in whole rupees; the invoice keeps the paise as
        // a round-off line so the arithmetic on the paper still adds up.
        var payable = Math.round(subtotal - discount);
        var tendered = this.tendered();

        var change = Math.max(0, tendered - payable);
        var due = Math.max(0, payable - tendered);

        writeAll(this.form, '[data-pos-discount]', money(discount));
        writeAll(this.form, '[data-pos-payable]', money(payable));
        writeAll(this.form, '[data-pos-tendered]', money(tendered));
        writeAll(this.form, '[data-pos-change]', money(change));
        writeAll(this.form, '[data-pos-due]', money(due));

        this.toggle('[data-pos-change-row]', change > 0.004);
        this.toggle('[data-pos-due-row]', due > 0.004);

        this.warn(due, payable);

        this.payable = payable;
        this.due = due;
    };

    Pos.prototype.toggle = function (selector, visible) {
        var element = this.form.querySelector(selector);

        if (element) {
            element.hidden = !visible;
        }
    };

    /**
     * Say what will go wrong before the server says it.
     *
     * Three refusals, worded as the three different conversations they are:
     * no right to sell on credit, no customer to owe, and not enough credit
     * left on the account.
     */
    Pos.prototype.warn = function (due, payable) {
        var box = this.form.querySelector('[data-pos-credit-warning]');
        var submit = this.form.querySelector('[data-pos-submit]');

        if (!box) {
            return;
        }

        var message = null;

        if (due > 0.004 && payable > 0) {
            if (!this.canCredit) {
                message = 'Leaving â‚¹' + money(due) + ' unpaid needs the credit sale right.';
            } else if (!this.customer) {
                message = 'Choose a customer â€” a walk-in has no account to owe â‚¹' + money(due) + ' on.';
            } else if (!this.customer.allow_credit) {
                message = this.customer.name + ' is cash only. Take the full â‚¹' + money(payable) + '.';
            } else if (due > this.customer.available_credit + 0.004) {
                message = this.customer.name + ' has only â‚¹'
                    + money(this.customer.available_credit) + ' of credit left.';
            }
        }

        box.hidden = message === null;
        box.textContent = message || '';

        if (submit) {
            // Left clickable on purpose: the server is the authority, and a
            // dead button with no explanation is worse than a clear refusal.
            submit.classList.toggle('is-blocked', message !== null);
        }
    };

    /* ---------------------------------------------------------- keyboard */

    Pos.prototype.wireKeyboard = function () {
        var self = this;

        document.addEventListener('keydown', function (event) {
            if (event.key === 'F2') {
                event.preventDefault();
                var scan = self.form.querySelector('[data-line-search]');

                if (scan) { scan.focus(); scan.select(); }

                return;
            }

            if (event.key === 'F4') {
                event.preventDefault();
                var amount = self.tenderBody.querySelector('[data-tender-amount]');

                if (amount) { amount.focus(); amount.select(); }

                return;
            }

            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                self.form.requestSubmit();
            }
        });
    };

    /* -------------------------------------------------------- completion */

    Pos.prototype.wireCompletion = function () {
        var self = this;

        // line-items.js fires these; the right-hand column follows the cart.
        ['line:added', 'line:removed', 'line:changed'].forEach(function (name) {
            self.form.addEventListener(name, function () { self.recalculate(); });
        });

        /*
         | A completed sale clears the screen and offers the receipt. It does
         | not navigate away: the next customer is already waiting, and the
         | scan box has to be live again immediately.
         */
        this.form.addEventListener('ajax:success', function (event) {
            var data = (event.detail && event.detail.data) || {};

            self.reset();

            if (data.receipt_url) {
                self.offerReceipt(data);
            }
        });
    };

    Pos.prototype.reset = function () {
        var body = this.cart.querySelector('[data-line-body]');

        if (body) {
            body.innerHTML = '';
        }

        if (window.LineItems) {
            window.LineItems.recalculate(this.cart);
        }

        /*
         | Tell the menu grid the bill is empty.
         |
         | Every dish card reads its count back off the cart, and it only
         | re-reads when a line event fires. Emptying the cart by hand here
         | fired nothing, so the card of whatever was just sold sat there
         | still showing "20" over an order that had already been rung up and
         | cleared - and the next customer's first tap started from there.
         */
        this.cart.dispatchEvent(new CustomEvent('line:removed', {
            bubbles: true,
            detail: { row: null },
        }));

        var picker = this.form.querySelector('[data-customer-picker]');

        if (picker && window.CustomerPicker) {
            window.CustomerPicker.clear(picker);
        }

        if (this.discountAmount) { this.discountAmount.value = 0; }
        if (this.discountPercent) { this.discountPercent.value = 0; }

        var walkIn = this.form.querySelector('[name="walk_in_name"]');
        if (walkIn) { walkIn.value = ''; }

        this.tenderBody.innerHTML = '';
        this.tenderIndex = 0;
        this.addTender();

        this.recalculate();

        var scan = this.form.querySelector('[data-line-search]');
        if (scan) { scan.focus(); }
    };

    /**
     * Offer the receipt without stealing focus from the scan box.
     *
     * A print dialog that opens itself would block the next sale, so this is
     * a link the cashier presses when they want paper.
     */
    Pos.prototype.offerReceipt = function (data) {
        if (!window.Toast) {
            return;
        }

        var link = document.createElement('a');
        link.className = 'btn btn-sm btn-primary';
        link.href = data.receipt_url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Print ' + data.number;

        var host = document.querySelector('.toast-stack .toast:last-child .toast-message');

        if (host) {
            host.appendChild(document.createElement('br'));
            host.appendChild(link);
        }
    };

    /* --------------------------------------------------------------- boot */

    function boot() {
        document.querySelectorAll('[data-pos-form]').forEach(function (form) {
            if (!form.__posReady) {
                form.__posReady = true;
                new Pos(form);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);

/*
|------------------------------------------------------------------------------
| Hold and resume (§6)
|------------------------------------------------------------------------------
|
| Separate from the Pos object above on purpose. Holding a sale is not part of
| ringing one up - it posts the same form somewhere else and walks away - and
| threading it through the tender arithmetic would tangle two things that have
| nothing to say to each other.
|
| Resuming is the other half, and it lives on the Held Sales screen: that page
| fetches the payload and rebuilds the form. Both are plain fetch calls with
| the form's own fields, so nothing here has to know what a cart looks like.
*/
(function (window, document) {
    'use strict';

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function num(value) {
        var parsed = parseFloat(value);

        return isNaN(parsed) ? 0 : parsed;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pos-hold]');

        if (!button) { return; }

        event.preventDefault();

        var form = button.closest('form');
        if (!form) { return; }

        /*
         | Refused here as well as on the server, because the server's answer
         | would arrive as a toast after the cashier had already turned away.
         */
        if (!form.querySelector('[name^="items["]')) {
            if (window.Toast) { window.Toast.error('There is nothing on this sale to hold.'); }
            return;
        }

        var label = window.prompt('Name this held sale (optional) — a customer, a table, anything you can find it by:', '');

        // A cancelled prompt means "changed my mind", not "no label".
        if (label === null) { return; }

        var body = new FormData(form);
        body.append('label', label);

        /*
         | What the basket comes to.
         |
         | The running total is worked out in the browser and shown on screen;
         | it is not a form field, so a plain FormData of the form carried no
         | total at all and every held sale was filed as zero. The Held Sales
         | list then showed a column of 0.00 that told a cashier nothing about
         | which basket was which.
         |
         | Sent as a figure the server may sanity-check rather than trust -
         | see PosController::park(), which reprices the lines itself when
         | this is missing or nonsense.
         */
        var payable = form.querySelector('[data-pos-payable]');

        if (payable) {
            body.append('grand_total', String(num((payable.textContent || '').replace(/[^0-9.\-]/g, ''))));
        }

        button.disabled = true;

        fetch(button.getAttribute('data-hold-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() },
            body: body
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            if (!result.ok) {
                throw new Error(result.payload.message || 'That sale could not be held.');
            }

            if (window.Toast) { window.Toast.success(result.payload.message); }

            // Straight back to an empty till. A cashier who held a sale is
            // already serving the next person.
            window.location.reload();
        }).catch(function (error) {
            button.disabled = false;

            if (window.Toast) { window.Toast.error(error.message); }
        });
    });
})(window, document);

/*
|------------------------------------------------------------------------------
| Resuming a held sale (§6)
|------------------------------------------------------------------------------
|
| Two halves, on two pages.
|
| On the Held Sales screen, "Resume" claims the sale - the server deletes it
| and hands back the basket, so two cashiers cannot both pick up the same one.
| The basket is stashed and the browser goes to the till.
|
| On the till, anything stashed is drawn into the form and the stash is
| cleared. sessionStorage rather than a query string: a basket is too big for
| a URL, and a URL somebody bookmarked would re-add the same sale for ever.
|
| Cleared before the rows are drawn, deliberately. If hydrating throws
| half-way the cashier gets a partial basket they can see and fix; leaving the
| stash in place would re-add those lines on the next page load too.
*/
(function (window, document) {
    'use strict';

    var STASH = 'erp.pos.resume';

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function stash(value) {
        try {
            window.sessionStorage.setItem(STASH, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }

    function takeStash() {
        try {
            var raw = window.sessionStorage.getItem(STASH);
            window.sessionStorage.removeItem(STASH);

            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    /* ------------------------------------------------- claim it and go */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pos-resume]');

        if (!button) { return; }

        event.preventDefault();
        button.disabled = true;

        fetch(button.getAttribute('data-resume-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() }
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            if (!result.ok) {
                throw new Error(result.payload.message || 'That sale could not be resumed.');
            }

            var data = result.payload.data || {};

            if (!stash({ payload: data.payload, products: data.products, message: result.payload.message })) {
                throw new Error('This browser would not hold the basket. Try again in a normal window.');
            }

            window.location.href = button.getAttribute('data-terminal-url');
        }).catch(function (error) {
            button.disabled = false;

            if (window.Toast) { window.Toast.error(error.message); }
        });
    });

    /* ------------------------------------------------ draw it at the till */

    function hydrate() {
        var container = document.querySelector('[data-line-items]');

        if (!container || !window.LineItems) { return; }

        // Taken (and cleared) before anything is drawn - see the comment above.
        var stashed = takeStash();

        if (!stashed || !stashed.payload) { return; }

        var payload = stashed.payload;
        var products = stashed.products || {};

        (payload.items || []).forEach(function (line) {
            var product = products[line.product_id];

            // A product removed from the menu while the sale was held. The
            // server has already said how many; skipping quietly here is the
            // rest of that answer.
            if (!product) { return; }

            var row = window.LineItems.add(container, product, { forceNewRow: true });

            if (!row) { return; }

            set(row, '[data-line-quantity]', line.quantity);
            set(row, '[name$="[unit_price]"]', line.unit_price);
            set(row, '[name$="[discount_percent]"]', line.discount_percent);
            set(row, '[name$="[discount_amount]"]', line.discount_amount);
        });

        window.LineItems.recalculate(container);

        // The bill-level fields, which live outside the cart.
        setById('[name="invoice_discount"]', payload.invoice_discount);
        setById('[name="invoice_discount_percent"]', payload.invoice_discount_percent);
        setById('[name="notes"]', payload.notes);
        setById('[name="walk_in_name"]', payload.walk_in_name);
        setById('[name="walk_in_mobile"]', payload.walk_in_mobile);

        if (window.Toast && stashed.message) {
            window.Toast.success(stashed.message);
        }
    }

    function set(row, selector, value) {
        if (value === undefined || value === null || value === '') { return; }

        var field = row.querySelector(selector);

        if (field) {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function setById(selector, value) {
        if (value === undefined || value === null || value === '') { return; }

        var field = document.querySelector(selector);

        if (field) {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrate);
    } else {
        hydrate();
    }
})(window, document);
