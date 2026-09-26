/*
|------------------------------------------------------------------------------
| Permission matrix
|------------------------------------------------------------------------------
| Drives <x-permission-matrix />: live search, cascading select-all at the
| global / module / sub-module levels, tri-state parent checkboxes, and the
| running counters.
|
| Saving is handled by the surrounding <form data-ajax> in app.js - this file
| only manages selection state.
*/
(function (window, document) {
    'use strict';

    /**
     * Set a parent checkbox from the state of the boxes it governs.
     * `indeterminate` is what makes a partially-filled group readable.
     */
    function reflect(parent, boxes) {
        if (!parent) { return; }

        var total = boxes.length;
        var checked = boxes.filter(function (b) { return b.checked; }).length;

        parent.checked = total > 0 && checked === total;
        parent.indeterminate = checked > 0 && checked < total;
    }

    function itemsIn(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-pm-item]'));
    }

    /** Only rows currently visible take part in select-all, so the button
     *  respects an active search rather than silently ticking hidden rows. */
    function visibleItemsIn(scope) {
        return itemsIn(scope).filter(function (item) {
            var row = item.closest('[data-pm-row]');
            return !row || !row.hidden;
        });
    }

    function refresh(matrix) {
        var all = itemsIn(matrix);
        var checkedCount = all.filter(function (b) { return b.checked; }).length;

        matrix.querySelectorAll('[data-pm-module]').forEach(function (module) {
            var boxes = itemsIn(module);
            reflect(module.querySelector('[data-pm-module-all]'), boxes);

            var count = module.querySelector('[data-pm-module-count]');
            if (count) {
                count.textContent = boxes.filter(function (b) { return b.checked; }).length +
                    ' / ' + boxes.length;
            }

            module.querySelectorAll('[data-pm-row]').forEach(function (row) {
                reflect(row.querySelector('[data-pm-row-all]'), itemsIn(row));
            });
        });

        var total = matrix.querySelector('[data-pm-count]');
        if (total) {
            total.textContent = checkedCount + ' / ' + (matrix.dataset.total || all.length);
        }
    }

    function setAll(boxes, checked) {
        boxes.forEach(function (box) {
            if (!box.disabled) { box.checked = checked; }
        });
    }

    function search(matrix, term) {
        var needle = term.trim().toLowerCase();

        matrix.querySelectorAll('[data-pm-module]').forEach(function (module) {
            var anyVisible = false;

            module.querySelectorAll('[data-pm-row]').forEach(function (row) {
                var hit = !needle || (row.dataset.haystack || '').indexOf(needle) !== -1;
                row.hidden = !hit;
                if (hit) { anyVisible = true; }
            });

            // Hide a module whose rows all filtered out.
            module.hidden = !anyVisible;
        });

        var empty = matrix.querySelector('[data-pm-empty]');
        if (empty) {
            var anyModule = Array.prototype.slice
                .call(matrix.querySelectorAll('[data-pm-module]'))
                .some(function (m) { return !m.hidden; });
            empty.hidden = anyModule;
        }
    }

    function bind(matrix) {
        refresh(matrix);

        matrix.addEventListener('change', function (event) {
            var target = event.target;

            if (target.matches('[data-pm-item]')) {
                refresh(matrix);
                return;
            }

            if (target.matches('[data-pm-row-all]')) {
                setAll(itemsIn(target.closest('[data-pm-row]')), target.checked);
                refresh(matrix);
                return;
            }

            if (target.matches('[data-pm-module-all]')) {
                setAll(visibleItemsIn(target.closest('[data-pm-module]')), target.checked);
                refresh(matrix);
            }
        });

        matrix.addEventListener('click', function (event) {
            if (event.target.closest('[data-pm-select-all]')) {
                event.preventDefault();
                setAll(visibleItemsIn(matrix), true);
                refresh(matrix);
                return;
            }

            if (event.target.closest('[data-pm-clear-all]')) {
                event.preventDefault();
                setAll(visibleItemsIn(matrix), false);
                refresh(matrix);
            }
        });

        var box = matrix.querySelector('[data-pm-search]');
        if (box) {
            var timer = null;
            box.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    search(matrix, box.value);
                }, 120);
            });

            // Esc clears the filter rather than the browser's search reset.
            box.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    box.value = '';
                    search(matrix, '');
                }
            });
        }
    }

    function init() {
        document.querySelectorAll('[data-permission-matrix]').forEach(bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PermissionMatrix = { refresh: refresh };
})(window, document);
