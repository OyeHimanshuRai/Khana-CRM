/*
|------------------------------------------------------------------------------
| Add-on answers: the repeating rows
|------------------------------------------------------------------------------
|
| A much smaller thing than js/line-items.js and deliberately separate from it:
| that file looks products up, prices lines and totals a document. This one
| clones a row and renumbers some names. Folding these together would put a
| lookup URL and a totals contract on a form that has neither.
|
| Markup contract:
|
|   <div data-modifier-options>
|     <tbody data-option-body>
|       <tr data-option-row> … <button data-option-remove> … </tr>
|     </tbody>
|     <button data-option-add>
|     <template data-option-template> …one <tr> with {{i}} in every name… </template>
|   </div>
|
| Indices are renumbered after every add and every removal, so the server
| always receives a dense `options[0..n]` array. A sparse one still works in
| PHP, but it makes `options.3.name` in a validation error point at a row the
| user cannot count to.
|
| Delegated from document, so a form swapped into a modal needs no init call.
|
| Depends on: nothing.
*/
(function (window, document) {
    'use strict';

    /* --------------------------------------------------------------- util */

    function container(element) {
        return element.closest ? element.closest('[data-modifier-options]') : null;
    }

    function rows(box) {
        return Array.prototype.slice.call(box.querySelectorAll('[data-option-row]'));
    }

    /**
     * Renumber every options[...] name from the top.
     *
     * Works on the name attribute rather than on a data-index, because the
     * name is the only thing the server sees and keeping a second source of
     * truth in sync is how they stop being in sync.
     */
    function renumber(box) {
        rows(box).forEach(function (row, index) {
            Array.prototype.forEach.call(row.querySelectorAll('[name^="options["]'), function (field) {
                field.name = field.name.replace(/^options\[\d*\]/, 'options[' + index + ']');
            });

            /* Labels inside a cloned row would otherwise all point at the id
               they were cloned from, which makes every one of them focus the
               first row's input. */
            Array.prototype.forEach.call(row.querySelectorAll('[id^="opt-"]'), function (field) {
                var fresh = field.id.replace(/-\d+$/, '-' + index);
                var label = row.querySelector('label[for="' + field.id + '"]');

                field.id = fresh;

                if (label) {
                    label.setAttribute('for', fresh);
                }
            });
        });
    }

    /* ---------------------------------------------------------------- add */

    function add(box) {
        var template = box.querySelector('[data-option-template]');
        var body = box.querySelector('[data-option-body]');

        if (!template || !body) {
            return;
        }

        var index = rows(box).length;

        /* The template's markup carries {{i}} where the index goes. Built as
           a string rather than by walking the clone, because the same token
           appears in half a dozen attributes and missing one would post two
           rows under one index. */
        var html = template.innerHTML.replace(/\{\{i\}\}/g, String(index));
        var host = document.createElement('tbody');

        host.innerHTML = html;

        var row = host.querySelector('[data-option-row]');

        if (!row) {
            return;
        }

        body.appendChild(row);
        renumber(box);

        var first = row.querySelector('input[type="text"]');

        if (first) {
            first.focus();
        }
    }

    /* ------------------------------------------------------------- remove */

    function remove(button) {
        var box = container(button);
        var row = button.closest('[data-option-row]');

        if (!box || !row) {
            return;
        }

        /*
         | The last row is emptied rather than removed. A question with no
         | answers is refused by the server, and leaving the user with a
         | table they cannot type into - and an Add button they have to find -
         | is a worse way to tell them so.
         */
        if (rows(box).length === 1) {
            Array.prototype.forEach.call(row.querySelectorAll('input'), function (field) {
                if (field.type === 'checkbox') {
                    field.checked = field.name.indexOf('is_available') !== -1;
                } else if (field.type !== 'hidden') {
                    field.value = field.type === 'number' ? '0' : '';
                }
            });

            return;
        }

        row.parentNode.removeChild(row);
        renumber(box);
    }

    /* ---------------------------------------------------------------- wire */

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('[data-option-add]');

        if (addButton) {
            event.preventDefault();
            var box = container(addButton);

            if (box) {
                add(box);
            }

            return;
        }

        var removeButton = event.target.closest('[data-option-remove]');

        if (removeButton) {
            event.preventDefault();
            remove(removeButton);
        }
    });

})(window, document);
