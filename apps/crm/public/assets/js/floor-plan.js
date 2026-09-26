/*
|------------------------------------------------------------------------------
| Floor plan: drag a table into place
|------------------------------------------------------------------------------
|
| Markup contract - everything is data attributes, nothing is hard-coded:
|
|   <div data-floor-plan
|        data-plan-editable="1"
|        data-position-url="/admin/tables/__ID__/position"
|        data-csrf="…">
|
|     <div data-plan-tile data-table-id="7" style="left:20%; top:35%"> … </div>
|   </div>
|
| `data-plan-editable` is written by the view only when the reader holds
| dining.tables.adjust, so a captain without the right still gets a plan they
| can read and open - it simply does not move under them. The server checks
| the same permission again; this only decides whether to bother the user.
|
| Pointer Events rather than mouse+touch, so a tablet and a mouse are one code
| path. `setPointerCapture` is what keeps a fast drag from escaping the tile
| and stranding it mid-air.
|
| Positions are percentages of the canvas, not pixels - see the migration. The
| save is debounced per tile and fires on drop rather than on every frame: a
| drag across a plan is forty pointermove events and forty PUTs would be forty
| rows in the activity log and a lock convoy on one table.
|
| Delegated from document, and re-attached to nothing: a plan swapped in by
| AjaxList carries its own tiles and this file finds them on the next
| pointerdown. That is why there is no init() to call after a refresh.
|
| Depends on: public/assets/js/app.js (Toast)
*/
(function (window, document) {
    'use strict';

    /* A tile is centred on its coordinate, so it may sit at the very edge
       without half of it leaving the canvas. Clamped anyway, because a drag
       that ends off-screen has no way back except the database. */
    var MIN = 0;
    var MAX = 100;

    var dragging = null;

    /* --------------------------------------------------------------- util */

    function clamp(value) {
        if (value < MIN) { return MIN; }
        if (value > MAX) { return MAX; }

        return value;
    }

    function toast(type, message) {
        if (window.Toast && window.Toast[type]) {
            window.Toast[type](message);
        }
    }

    function plan(element) {
        return element.closest ? element.closest('[data-floor-plan]') : null;
    }

    function editable(canvas) {
        return !!(canvas && canvas.hasAttribute('data-plan-editable'));
    }

    /* ---------------------------------------------------------------- drag */

    /**
     * Where the pointer is, as a percentage of the canvas.
     *
     * Read from the canvas's own rect every move rather than cached on
     * pointerdown: the sidebar collapses, the window resizes, and a cached
     * rect would put the tile somewhere the pointer is not.
     */
    function percentage(canvas, event) {
        var rect = canvas.getBoundingClientRect();

        if (!rect.width || !rect.height) {
            return null;
        }

        return {
            x: clamp(((event.clientX - rect.left) / rect.width) * 100),
            y: clamp(((event.clientY - rect.top) / rect.height) * 100)
        };
    }

    function start(event) {
        /* Left button or a touch/pen contact only. A right-click that began a
           drag would leave the tile stuck to the pointer with no drop. */
        if (event.button !== 0) {
            return;
        }

        var tile = event.target.closest('[data-plan-tile]');

        if (!tile) {
            return;
        }

        /* Anything the tile offers as a control stays a control. Dragging
           from the status dropdown would make it unusable on a touchscreen,
           where there is no way to tell a tap from the start of a drag. */
        if (event.target.closest('select, option, button, a, input, label')) {
            return;
        }

        var canvas = plan(tile);

        if (!editable(canvas)) {
            return;
        }

        dragging = {
            tile: tile,
            canvas: canvas,
            pointerId: event.pointerId,
            moved: false
        };

        tile.classList.add('is-dragging');

        /* Keeps every subsequent move and the up event on this element even
           if the pointer outruns it. */
        if (tile.setPointerCapture) {
            tile.setPointerCapture(event.pointerId);
        }

        event.preventDefault();
    }

    function move(event) {
        if (!dragging || event.pointerId !== dragging.pointerId) {
            return;
        }

        var position = percentage(dragging.canvas, event);

        if (!position) {
            return;
        }

        dragging.moved = true;
        dragging.position = position;

        dragging.tile.style.left = position.x + '%';
        dragging.tile.style.top = position.y + '%';
    }

    function end(event) {
        if (!dragging || event.pointerId !== dragging.pointerId) {
            return;
        }

        var current = dragging;

        dragging = null;
        current.tile.classList.remove('is-dragging');

        if (current.tile.releasePointerCapture) {
            try {
                current.tile.releasePointerCapture(current.pointerId);
            } catch (e) {
                /* Already released - the capture is gone either way. */
            }
        }

        /* A press that never moved is a click on the tile, which the face
           button handles. Saving here would write the position it already
           has and log a change nobody made. */
        if (!current.moved || !current.position) {
            return;
        }

        save(current.canvas, current.tile, current.position);
    }

    /**
     * Cancel, not drop.
     *
     * Fires when the browser takes the pointer away mid-drag - a system
     * gesture, a context menu, the page scrolling under a touch. The tile is
     * left where the last move put it on screen but nothing is saved, so a
     * refresh puts it back where it was. That is the honest outcome: the user
     * did not finish the gesture.
     */
    function cancel(event) {
        if (!dragging || event.pointerId !== dragging.pointerId) {
            return;
        }

        dragging.tile.classList.remove('is-dragging');
        dragging = null;
    }

    /* ---------------------------------------------------------------- save */

    function save(canvas, tile, position) {
        var template = canvas.getAttribute('data-position-url');
        var id = tile.getAttribute('data-table-id');

        if (!template || !id) {
            return;
        }

        var body = new FormData();

        body.append('_method', 'PUT');
        body.append('_token', canvas.getAttribute('data-csrf') || '');
        body.append('pos_x', position.x.toFixed(3));
        body.append('pos_y', position.y.toFixed(3));

        window.fetch(template.replace('__ID__', id), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            if (payload && payload.success === false) {
                toast('error', payload.message || 'That position could not be saved.');
            }
        }).catch(function () {
            /*
             | Said out loud rather than swallowed. The tile is sitting where
             | the user dropped it, so silence would read as saved - and the
             | next reload would quietly undo their afternoon's arranging.
             */
            toast('error', 'Position not saved — check your connection and drag it again.');
        });
    }

    /* ---------------------------------------------------------------- wire */

    document.addEventListener('pointerdown', start);
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', cancel);

})(window, document);
