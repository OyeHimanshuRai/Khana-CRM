/*
|------------------------------------------------------------------------------
| Document line editor
|------------------------------------------------------------------------------
|
| The repeating-rows control behind every document that has line items:
| stock adjustments, stock transfers, purchase orders, goods receipts and the
| POS cart. One implementation, because they differ in which columns they
| show and in nothing else that matters.
|
| Markup contract - everything is data attributes, nothing is hard-coded:
|
|   <div data-line-items
|        data-lookup-url="/admin/products/lookup"
|        data-empty="No lines yet">
|
|     <input data-line-search placeholder="Scan a barcode or search…">
|     <div data-line-results hidden></div>
|
|     <tbody data-line-body></tbody>
|     <template data-line-template> …one <tr> with {{tokens}}… </template>
|
|     <span data-line-count></span>
|     <span data-line-total-quantity></span>
|     <span data-line-total-value></span>
|   </div>
|
| Tokens available in the row template:
|
|   {{index}}          array index, for the input names
|   {{product_id}} {{name}} {{sku}} {{barcode}} {{unit}}
|   {{price}} {{mrp}} {{on_hand}} {{available}}
|   {{stock_note}}     "8 PCS in stock", or "Made to order" for a dish
|   {{quantity}}       starting quantity, always 1
|   {{step}}           "1" or "0.001", from the unit's own rule
|
| Optional on the container:
|
|   data-line-stock-cap   refuse a quantity above what is on the shelf. Set by
|                         the till for a shop that does not allow negative
|                         stock; never set on a purchase document, where a
|                         quantity is what is being bought rather than sold.
|
| A row's own inputs are named items[{{index}}][…]. Indices are renumbered
| after every removal, so the server always receives a dense array.
|
| Rows carry data-line-row and, inside them:
|   [data-line-quantity]   the quantity input
|   [data-line-price]      unit price/cost, when the document prices lines
|   [data-line-amount]     read-only per-row total, filled in by this file
|   [data-line-remove]     the remove button
|
| Behaviour worth knowing:
|
|   - A barcode that resolves to exactly one product is added immediately,
|     without showing a picker. That is what a scan has to feel like. POS can
|     turn that off per shop with data-scanner-auto-enter="0", which sends the
|     match to the picker instead; an explicit Enter still adds it either way.
|   - Adding a product that is already in the list bumps its quantity rather
|     than making a second row; scanning three tins should read as three.
|   - Whole-number units refuse fractional quantities, because the unit says
|     they are not divisible.
|
| Events dispatched on the container (all bubble):
|   line:added, line:removed, line:changed  — detail: { row, totals }
|
| Depends on: public/assets/js/app.js (Toast)
*/
(function (window, document) {
    'use strict';

    var SEARCH_DEBOUNCE = 220;

    /* --------------------------------------------------------------- util */

    function money(value) {
        return (Math.round(value * 100) / 100).toFixed(2);
    }

    function num(value) {
        var parsed = parseFloat(value);

        return isNaN(parsed) ? 0 : parsed;
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

    function toast(type, message) {
        if (window.Toast && window.Toast[type]) {
            window.Toast[type](message);
        }
    }

    /* ------------------------------------------------------------- lookup */

    /**
     * Ask the server about a search term.
     *
     * Resolves to { exact, results }. `exact` is set only when the term was
     * a barcode or SKU that matched one product outright.
     */
    function lookup(container, term) {
        var url = container.getAttribute('data-lookup-url');

        if (!url) {
            return Promise.resolve({ exact: null, results: [] });
        }

        var separator = url.indexOf('?') === -1 ? '?' : '&';

        return window.fetch(url + separator + 'q=' + encodeURIComponent(term), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken()
            },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var data = (payload && payload.data) || {};

                return { exact: data.exact || null, results: data.results || [] };
            })
            .catch(function () {
                toast('error', 'Could not reach the product lookup.');

                return { exact: null, results: [] };
            });
    }

    /* --------------------------------------------------------------- rows */

    function body(container) {
        return container.querySelector('[data-line-body]');
    }

    function rows(container) {
        return Array.prototype.slice.call(container.querySelectorAll('[data-line-row]'));
    }

    function findRow(container, productId, batchId) {
        return rows(container).filter(function (row) {
            var sameProduct = row.getAttribute('data-product-id') === String(productId);
            var sameBatch = (row.getAttribute('data-batch-id') || '') === String(batchId || '');

            return sameProduct && sameBatch;
        })[0] || null;
    }

    /**
     * Fill the row template for one product.
     */
    function render(container, product, index) {
        var template = container.querySelector('[data-line-template]');

        if (!template) {
            return null;
        }

        var step = product.allow_decimal ? '0.001' : '1';

        // "· 8901234500011", or nothing at all for a product with no
        // barcode - a lone separator with nothing after it would look like
        // a rendering bug, not an empty field.
        var barcodeSuffix = product.barcode ? ' · ' + escapeHtml(product.barcode) : '';

        /*
         | What the row says about stock.
         |
         | A dish is made to order, so it has no shelf to count and nothing
         | is taken off one when it sells. Printing a number against it would
         | be telling the cashier something the sale is never going to move -
         | which is exactly how a till ends up capping, or failing to cap, a
         | quantity against a figure that means nothing.
         */
        var stockNote = product.is_made_to_order
            ? 'Made to order'
            : num(product.on_hand) + ' ' + escapeHtml(product.unit || '') + ' in stock';

        var html = template.innerHTML
            .replace(/\{\{index\}\}/g, index)
            .replace(/\{\{product_id\}\}/g, escapeHtml(product.id))
            .replace(/\{\{name\}\}/g, escapeHtml(product.name))
            .replace(/\{\{sku\}\}/g, escapeHtml(product.sku))
            .replace(/\{\{barcode\}\}/g, escapeHtml(product.barcode || ''))
            .replace(/\{\{barcode_suffix\}\}/g, barcodeSuffix)
            .replace(/\{\{unit\}\}/g, escapeHtml(product.unit || ''))
            .replace(/\{\{price\}\}/g, money(num(product.selling_price)))
            .replace(/\{\{cost\}\}/g, money(num(product.average_cost || product.selling_price)))
            .replace(/\{\{mrp\}\}/g, money(num(product.mrp)))
            .replace(/\{\{on_hand\}\}/g, num(product.on_hand))
            .replace(/\{\{available\}\}/g, num(product.available))
            .replace(/\{\{stock_note\}\}/g, stockNote)
            .replace(/\{\{quantity\}\}/g, '1')
            .replace(/\{\{step\}\}/g, step);

        /*
         | Parsed inside a <template>, not a <tbody>. Template content is the
         | one context that accepts both a <tr> (every document form) and a
         | plain element (the counter's cart, which is a list of cards) - a
         | <tbody> would silently foster-parent the second kind out of
         | existence and the row would never appear.
         */
        var wrapper = document.createElement('template');
        wrapper.innerHTML = html.trim();

        var row = wrapper.content.querySelector('[data-line-row]');

        if (!row) {
            return null;
        }

        row.setAttribute('data-product-id', product.id);
        row.setAttribute('data-allow-decimal', product.allow_decimal ? '1' : '0');
        row.setAttribute('data-on-hand', num(product.on_hand));
        row.setAttribute('data-tracks-stock', product.is_made_to_order ? '0' : '1');
        row.setAttribute('data-line-name', product.name || '');

        return row;
    }

    /* --------------------------------------------------------- stock cap */

    /**
     * The most of this row the shelf can supply, or null when nothing caps it.
     *
     * Null - not zero - for every case where a cap would be a fiction: a
     * document that is not selling (a purchase order buys what is not there
     * yet), a shop that allows negative stock, and a dish, which has no
     * count of itself at all. Returning zero for those would refuse the sale
     * of something perfectly sellable.
     */
    function capFor(container, row) {
        if (!container.hasAttribute('data-line-stock-cap')) {
            return null;
        }

        if (row.getAttribute('data-tracks-stock') === '0') {
            return null;
        }

        var onHand = row.getAttribute('data-on-hand');

        return onHand === null ? null : num(onHand);
    }

    /**
     * Hold one row's quantity to what can actually be sold.
     *
     * Applied wherever a quantity can change - the +/- buttons, a typed
     * figure, a pasted one, a scan that bumps an existing row - rather than
     * only on the buttons. A cap the stepper honours and the keyboard does
     * not is not a cap; it is the appearance of one, which is worse, because
     * the cashier has been taught the screen would stop them.
     *
     * @returns {boolean} whether the value had to be pulled down.
     */
    function applyCap(container, row, options) {
        var cap = capFor(container, row);

        if (cap === null) {
            return false;
        }

        var input = row.querySelector('[data-line-quantity]');

        if (!input || input.value === '') {
            return false;
        }

        var wanted = num(input.value);

        if (wanted <= cap + 0.0005) {
            return false;
        }

        input.value = cap > 0 ? cap : '';

        if (!options || options.quiet !== true) {
            var name = row.getAttribute('data-line-name') || 'That item';

            toast('error', cap > 0
                ? name + ': only ' + cap + ' in stock.'
                : name + ' is out of stock.');
        }

        return true;
    }

    /**
     * Put a product into the list, or bump the one already there.
     *
     * @param {object} [options]
     * @param {boolean} [options.forceNewRow] Skip the merge-into-existing-row
     *   check and always render a fresh line. Off by default - used only by
     *   scanner.js, when Settings > Scanner > "Duplicate Scan = Qty +1" is
     *   turned off for a shop that wants every scan on its own line.
     */
    function add(container, product, options) {
        options = options || {};

        var existing = options.forceNewRow ? null : findRow(container, product.id, null);

        if (existing) {
            var input = existing.querySelector('[data-line-quantity]');

            if (input) {
                input.value = num(input.value) + 1;
                applyCap(container, existing);
                flash(existing);
                recalculate(container);
                emit(container, 'line:changed', existing);
            }

            return existing;
        }

        var row = render(container, product, rows(container).length);

        if (!row) {
            return null;
        }

        body(container).appendChild(row);

        // A fresh row starts at one, which is already one too many for
        // something the shelf has none of.
        applyCap(container, row);

        renumber(container);
        recalculate(container);
        emit(container, 'line:added', row);

        var quantity = row.querySelector('[data-line-quantity]');

        if (quantity) {
            quantity.focus();
            quantity.select();
        }

        return row;
    }

    function remove(container, row) {
        row.remove();

        renumber(container);
        recalculate(container);
        emit(container, 'line:removed', null);
    }

    /**
     * Renumber every row's input names so the server gets a dense array.
     *
     * Without this a removal leaves items[0], items[2] - which PHP reads as
     * a map, not a list, and every downstream `foreach` starts guessing.
     */
    function renumber(container) {
        rows(container).forEach(function (row, index) {
            row.setAttribute('data-line-index', index);

            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/items\[\d*\]/, 'items[' + index + ']');
            });
        });
    }

    /** Briefly highlight a row whose quantity just changed. */
    function flash(row) {
        row.classList.add('is-flash');
        window.setTimeout(function () { row.classList.remove('is-flash'); }, 450);
    }

    /* -------------------------------------------------------------- totals */

    function recalculate(container) {
        var totals = { count: 0, quantity: 0, value: 0 };

        rows(container).forEach(function (row) {
            var quantityInput = row.querySelector('[data-line-quantity]');
            var priceInput = row.querySelector('[data-line-price]');

            var quantity = num(quantityInput && quantityInput.value);

            // The unit decides whether a part quantity means anything.
            if (quantityInput && row.getAttribute('data-allow-decimal') !== '1') {
                var whole = Math.round(quantity);

                if (whole !== quantity) {
                    quantity = whole;
                    quantityInput.value = whole;
                }
            }

            var price = priceInput ? num(priceInput.value) : 0;
            var amount = quantity * price;

            var amountCell = row.querySelector('[data-line-amount]');

            if (amountCell) {
                amountCell.textContent = money(amount);

                var hidden = row.querySelector('[data-line-amount-input]');

                if (hidden) {
                    hidden.value = money(amount);
                }
            }

            totals.count += 1;
            totals.quantity += quantity;
            totals.value += amount;
        });

        write(container, '[data-line-count]', totals.count);
        write(container, '[data-line-total-quantity]', (Math.round(totals.quantity * 1000) / 1000));
        write(container, '[data-line-total-value]', money(totals.value));

        toggleEmpty(container, totals.count === 0);

        container.__lineTotals = totals;

        return totals;
    }

    function write(container, selector, value) {
        container.querySelectorAll(selector).forEach(function (element) {
            element.textContent = value;
        });
    }

    function toggleEmpty(container, isEmpty) {
        container.querySelectorAll('[data-line-empty]').forEach(function (element) {
            element.hidden = !isEmpty;
        });
    }

    function emit(container, name, row) {
        container.dispatchEvent(new CustomEvent(name, {
            bubbles: true,
            detail: { row: row, totals: container.__lineTotals || null }
        }));
    }

    /* ------------------------------------------------------------ results */

    function showResults(container, results) {
        var panel = container.querySelector('[data-line-results]');

        if (!panel) {
            return;
        }

        if (!results.length) {
            panel.innerHTML = '<div class="line-result is-empty">Nothing matched.</div>';
            panel.hidden = false;

            return;
        }

        panel.innerHTML = results.map(function (product, index) {
            var stock = num(product.on_hand);

            /*
             | A dish is never "out of stock" here.
             |
             | It has no count of itself and no sale moves one, so a zero
             | against it is not a shortage - it is the absence of a number
             | that was never meant to exist. Showing it in red taught the
             | counter that half the menu could not be sold.
             */
            var made = !!product.is_made_to_order;
            var stockClass = (made || stock > 0) ? '' : ' is-out';
            var stockNote = made
                ? 'made to order'
                : (stock > 0 ? stock + ' ' + escapeHtml(product.unit || '') : 'out of stock');

            return '<button type="button" class="line-result" data-line-pick="' + index + '">'
                + '<span class="line-result-name">' + escapeHtml(product.name) + '</span>'
                + '<span class="line-result-meta">' + escapeHtml(product.sku)
                + (product.barcode ? ' · ' + escapeHtml(product.barcode) : '')
                + '</span>'
                + '<span class="line-result-price">₹' + money(num(product.selling_price)) + '</span>'
                + '<span class="line-result-stock' + stockClass + '">'
                + stockNote
                + '</span>'
                + '</button>';
        }).join('');

        panel.hidden = false;
        panel.__results = results;
    }

    function hideResults(container) {
        var panel = container.querySelector('[data-line-results]');

        if (panel) {
            panel.hidden = true;
            panel.innerHTML = '';
            panel.__results = null;
        }
    }

    /* --------------------------------------------------------------- wire */

    /**
     * @param {boolean} [allowExact] Commit an exact barcode/SKU match without
     *   asking. True unless the caller is the debounced typing path on a
     *   screen with Settings > Barcode Scanner > "Auto Enter After Scan"
     *   turned off - see the input listener in init().
     */
    function search(container, term, allowExact) {
        if (term.length < 1) {
            hideResults(container);

            return;
        }

        lookup(container, term).then(function (payload) {
            /*
             | An exact barcode or SKU match is a scan, and a scan means
             | "this one" - so it goes straight in and the picker never
             | appears. Anything else is a search and needs a choice.
             |
             | Unless the shop asked to confirm every scan: then the match is
             | shown in the picker like any other result and Enter or a tap
             | adds it. The server sends that one product in `results` too,
             | so there is nothing to reconstruct here.
             */
            if (payload.exact && allowExact !== false) {
                add(container, payload.exact);
                clearSearch(container);

                return;
            }

            showResults(container, payload.results);
        });
    }

    function clearSearch(container) {
        var input = container.querySelector('[data-line-search]');

        if (input) {
            input.value = '';
            input.focus();
        }

        hideResults(container);
    }

    function init(container) {
        if (container.__lineReady) {
            return;
        }

        container.__lineReady = true;

        var input = container.querySelector('[data-line-search]');
        var timer = null;

        if (input) {
            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                var term = input.value.trim();

                /*
                 | Settings > Barcode Scanner > "Auto Enter After Scan". On
                 | (and everywhere the attribute is absent, which is every
                 | screen but POS), a code that names one product commits
                 | itself once typing stops - that is what makes a scanner
                 | with no Enter suffix usable at all.
                 |
                 | Off, the match waits in the picker for a real keystroke.
                 | Read per event, not once at wire-up, so a settings save
                 | that re-renders the container is picked up.
                 */
                var autoEnter = container.getAttribute('data-scanner-auto-enter') !== '0';

                timer = window.setTimeout(function () {
                    search(container, term, autoEnter);
                }, SEARCH_DEBOUNCE);
            });

            /*
             | Barcode scanners type fast and finish with Enter, so Enter has
             | to resolve immediately rather than wait out the debounce - and
             | it must not submit the surrounding form, which is what would
             | otherwise happen on a single-input row.
             */
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                // Settings > Barcode Scanner > "Auto Detect Scan" - off,
                // Enter still does nothing special and a scanner's trailing
                // Enter just sits in the box, unread. On by default: absent
                // the data attribute entirely (every screen but POS),
                // behaviour is exactly what it always was.
                if (container.getAttribute('data-scanner-auto-detect') === '0') {
                    return;
                }

                window.clearTimeout(timer);

                // Always allowed to commit an exact match, whatever "Auto
                // Enter After Scan" says: that setting exists to stop the
                // *automatic* path, and this keystroke is the operator.
                search(container, input.value.trim(), true);
            });

            input.addEventListener('blur', function () {
                // Delayed so a click on a result still lands.
                window.setTimeout(function () { hideResults(container); }, 160);
            });
        }

        container.addEventListener('click', function (event) {
            var pick = event.target.closest('[data-line-pick]');

            if (pick) {
                var panel = container.querySelector('[data-line-results]');
                var results = (panel && panel.__results) || [];
                var product = results[parseInt(pick.getAttribute('data-line-pick'), 10)];

                if (product) {
                    add(container, product);
                    clearSearch(container);
                }

                return;
            }

            var removeButton = event.target.closest('[data-line-remove]');

            if (removeButton) {
                var row = removeButton.closest('[data-line-row]');

                if (row) {
                    remove(container, row);
                }

                return;
            }

            /*
             | +/- next to the quantity box (see terminal.blade.php). Nudges
             | the same input a scanner or a typed value already writes to,
             | then fires a native 'input' event rather than recalculating
             | here directly - so this stays the one code path that reacts
             | to a quantity change, whoever caused it.
             */
            var step = event.target.closest('[data-qty-step]');

            if (step) {
                var stepRow = step.closest('[data-line-row]');
                var quantityInput = stepRow && stepRow.querySelector('[data-line-quantity]');

                if (quantityInput) {
                    var delta = num(step.getAttribute('data-qty-step'));
                    var next = num(quantityInput.value) + delta;
                    var minimum = num(quantityInput.getAttribute('min')) || 0.001;

                    quantityInput.value = Math.max(minimum, next);
                    applyCap(container, stepRow);
                    quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });

        container.addEventListener('input', function (event) {
            if (event.target.closest('[data-line-quantity], [data-line-price]')) {
                recalculate(container);
                emit(container, 'line:changed', event.target.closest('[data-line-row]'));
            }
        });

        /*
         | The cap on a typed figure lands on `change`, not `input`.
         |
         | Someone typing "12" passes through "1" on the way. Capping as they
         | type would rewrite the box under their fingers at the first digit;
         | capping when they leave it judges what they actually meant.
         */
        container.addEventListener('change', function (event) {
            var quantity = event.target.closest('[data-line-quantity]');

            if (!quantity) { return; }

            var row = quantity.closest('[data-line-row]');

            if (row && applyCap(container, row)) {
                recalculate(container);
                emit(container, 'line:changed', row);
            }
        });

        // Rows rendered by the server on an edit screen still need totals.
        renumber(container);
        recalculate(container);
    }

    function boot() {
        document.querySelectorAll('[data-line-items]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.LineItems = {
        init: init,
        add: add,
        recalculate: recalculate,
        rows: rows
    };
})(window, document);
