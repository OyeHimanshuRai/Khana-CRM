/*
| The counter's menu grid.
|
| Everything here ends in window.LineItems - the same cart the search box, the
| wireless scanner and the phone camera write to. This file owns no totals, no
| prices and no copy of the bill: the quantity on a dish card is read back off
| the cart every time the cart changes, so the grid can never disagree with
| what is being charged.
|
| Pairs with resources/views/admin/pos/terminal.blade.php and pos-terminal.css.
*/
(function (window, document) {
    'use strict';

    /* ------------------------------------------------------------ helpers */

    function num(value) {
        var parsed = parseFloat(value);

        return isNaN(parsed) ? 0 : parsed;
    }

    function dishes(root) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-dish]'));
    }

    /** The cart row for one product, if it is on the bill. */
    function rowFor(root, productId) {
        return root.querySelector('[data-line-row][data-product-id="' + productId + '"]');
    }

    /* --------------------------------------------------------- the cart */

    /**
     * Put one more of this dish on the bill.
     *
     * Straight to LineItems.add, which either appends a line or bumps the one
     * already there - exactly what a scan of the same barcode twice does.
     */
    function addOne(root, dish) {
        var payload;

        try {
            payload = JSON.parse(dish.getAttribute('data-payload'));
        } catch (error) {
            return;
        }

        if (window.LineItems && payload) {
            window.LineItems.add(root, payload);
        }
    }

    /**
     * Take one off the bill.
     *
     * Driven through the row's own controls rather than around them: setting
     * the quantity and firing `input` is what typing in the box does, and
     * clicking the remove button is what the cashier would do - so
     * line-items.js recalculates and emits on its own terms, and there is one
     * definition of what removing a line means.
     */
    function removeOne(root, dish) {
        var row = rowFor(root, dish.getAttribute('data-dish-id'));

        if (!row) {
            return;
        }

        var input = row.querySelector('[data-line-quantity]');

        if (!input) {
            return;
        }

        var next = num(input.value) - 1;

        if (next >= 1) {
            input.value = next;
            input.dispatchEvent(new Event('input', { bubbles: true }));

            return;
        }

        var remove = row.querySelector('[data-line-remove]');

        if (remove) {
            remove.click();
        }
    }

    /**
     * Read every dish's count back off the bill.
     *
     * One pass over the cart rather than a lookup per tile: a long menu with a
     * full bill would otherwise be a few hundred DOM queries on every
     * keystroke in a quantity box.
     */
    function sync(root) {
        var counts = {};

        root.querySelectorAll('[data-line-row]').forEach(function (row) {
            var id = row.getAttribute('data-product-id');
            var input = row.querySelector('[data-line-quantity]');

            if (id) {
                counts[id] = (counts[id] || 0) + num(input && input.value);
            }
        });

        dishes(root).forEach(function (dish) {
            var quantity = counts[dish.getAttribute('data-dish-id')] || 0;
            var output = dish.querySelector('[data-dish-qty]');
            var minus = dish.querySelector('[data-dish-step="-1"]');

            if (output) {
                // Whole numbers stay whole; a dish sold by weight keeps its
                // decimals rather than being rounded into a lie.
                output.textContent = quantity % 1 === 0 ? String(quantity) : quantity.toFixed(3);
            }

            if (minus) {
                minus.disabled = quantity <= 0;
            }

            dish.classList.toggle('is-in-cart', quantity > 0);
        });
    }

    /* ------------------------------------------------------------ filters */

    /**
     * Apply the category, food-type and search filters together.
     *
     * The search box here narrows what is already on the grid. It is not the
     * lookup: that one queries the whole catalogue and is wired by
     * line-items.js on the very same input, which is why a term that matches
     * nothing on the grid can still find a product on the server.
     */
    function filter(root, state) {
        var term = (state.term || '').trim().toLowerCase();
        var shown = 0;

        dishes(root).forEach(function (dish) {
            var matches = true;

            if (state.category && dish.getAttribute('data-category') !== state.category) {
                matches = false;
            }

            if (matches && state.food && dish.getAttribute('data-food-type') !== state.food) {
                matches = false;
            }

            if (matches && term) {
                matches = (dish.getAttribute('data-name') || '').indexOf(term) !== -1
                    || (dish.getAttribute('data-sku') || '').indexOf(term) !== -1;
            }

            dish.hidden = !matches;

            if (matches) {
                shown += 1;
            }
        });

        root.querySelectorAll('[data-menu-shown]').forEach(function (element) {
            element.textContent = shown;
        });

        root.querySelectorAll('[data-dish-empty]').forEach(function (element) {
            element.hidden = shown > 0;
        });
    }

    /** Mark the pressed button in a group, leaving the rest off. */
    function press(buttons, active) {
        buttons.forEach(function (button) {
            button.classList.toggle('is-on', button === active);
        });
    }

    /* --------------------------------------------------------------- init */

    function init(root) {
        if (root.__posMenu) {
            return;
        }

        root.__posMenu = true;

        var state = { category: '', food: '', term: '' };

        /* --------------------------------------------------- the grid */

        root.addEventListener('click', function (event) {
            var step = event.target.closest('[data-dish-step]');

            if (step) {
                var stepped = step.closest('[data-dish]');

                if (stepped) {
                    if (step.getAttribute('data-dish-step') === '1') {
                        addOne(root, stepped);
                    } else {
                        removeOne(root, stepped);
                    }
                }

                return;
            }

            // A tap anywhere else on the card is one more of it. Nothing is
            // added for a dish that cannot be sold - the card says why.
            var dish = event.target.closest('[data-dish]');

            if (dish && !dish.hasAttribute('data-off')) {
                addOne(root, dish);
            }
        });

        /* ------------------------------------------------- the filters */

        var categoryButtons = Array.prototype.slice.call(root.querySelectorAll('[data-category-filter]'));
        var foodButtons = Array.prototype.slice.call(root.querySelectorAll('[data-food-filter]'));

        categoryButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                state.category = button.getAttribute('data-category-filter');
                press(categoryButtons, button);
                filter(root, state);
            });
        });

        foodButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                state.food = button.getAttribute('data-food-filter');
                press(foodButtons, button);
                filter(root, state);
            });
        });

        var search = root.querySelector('[data-line-search]');

        if (search) {
            search.addEventListener('input', function () {
                state.term = search.value;
                filter(root, state);
            });

            /*
             | A scanner types its code and presses Enter. The code is a
             | barcode, not a dish name, so leaving it in the box would hide
             | the whole grid behind a term that matches nothing - and
             | line-items.js has already cleared the input by then.
             */
            search.addEventListener('search', function () {
                state.term = search.value;
                filter(root, state);
            });
        }

        /* --------------------------------------------- staying in step */

        ['line:added', 'line:changed', 'line:removed'].forEach(function (name) {
            root.addEventListener(name, function () {
                sync(root);

                // The scanner clears the box on a successful scan; the grid
                // has to come back with it.
                if (search && search.value !== state.term) {
                    state.term = search.value;
                    filter(root, state);
                }
            });
        });

        sync(root);
        filter(root, state);
    }

    function boot() {
        document.querySelectorAll('[data-pos-menu]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
