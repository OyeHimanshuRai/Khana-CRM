/*
 * Info popovers (.info) and long form hints folded into them.
 *
 * Two jobs:
 *
 *  1. Behaviour for <x-info>: a tap or click toggles the popover, Escape or a
 *     tap elsewhere closes it, and one is placed so it never runs off the edge
 *     of the screen. Hover and keyboard focus are pure CSS (see theme.css).
 *
 *  2. A long `.form-hint` under a field is folded into an info icon beside the
 *     field's label. Short hints (a unit, a limit, "optional") stay where they
 *     are - they are what you need while typing. A hint is only folded when it
 *     is longer than MIN_LENGTH characters and holds no link, and is never
 *     folded if it says something about an error.
 *
 *     Opt out with data-keep on the hint. Nothing is lost without the script:
 *     the hints just stay where they were written.
 */
(function () {
    'use strict';

    var MIN_LENGTH = 44;
    var uid = 0;

    function setOpen(info, open) {
        var btn = info.querySelector('.info-btn');
        info.classList.toggle('is-open', open);
        if (btn) { btn.setAttribute('aria-expanded', String(open)); }
        if (!open) { delete info.dataset.pinned; }
    }

    function closeAll(except) {
        Array.prototype.forEach.call(document.querySelectorAll('.info.is-open'), function (info) {
            if (info !== except) { setOpen(info, false); }
        });
    }

    /*
     * The popover is position: fixed (an absolutely positioned one is clipped by
     * the card or column it sits in), so it is placed here: below the "i" by
     * default, above it when there is no room underneath, and never closer than
     * 8px to a screen edge.
     */
    function place(info) {
        var btn = info.querySelector('.info-btn');
        var pop = info.querySelector('.info-pop');
        if (!btn || !pop) { return; }

        var b = btn.getBoundingClientRect();
        var width = pop.offsetWidth;
        var height = pop.offsetHeight;

        var left = Math.max(8, Math.min(b.left + b.width / 2 - 20, window.innerWidth - width - 8));
        var top = b.bottom + 8;
        var above = false;

        if (top + height > window.innerHeight - 8 && b.top - 8 - height >= 8) {
            top = b.top - 8 - height;
            above = true;
        }

        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
        pop.style.transformOrigin = (above ? 'bottom' : 'top') + ' left';
    }

    function open(info, pinned) {
        closeAll(info);
        setOpen(info, true);
        if (pinned) { info.dataset.pinned = '1'; }
        place(info);
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest && event.target.closest('.info-btn');
        if (btn) {
            event.preventDefault();
            var info = btn.closest('.info');
            if (info.classList.contains('is-open') && info.dataset.pinned) {
                setOpen(info, false);
            } else {
                open(info, true);
            }
            return;
        }
        if (!(event.target.closest && event.target.closest('.info-pop'))) { closeAll(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeAll(); }
    });

    /* A mouse hovers; a finger taps (and a tap also fires a synthetic mouseenter
       on some browsers, which would open the tip and have the click shut it). */
    document.addEventListener('pointerenter', function (event) {
        if (event.pointerType !== 'mouse') { return; }
        var info = event.target.closest && event.target.closest('.info');
        if (info && !info.classList.contains('is-open')) { open(info, false); }
    }, true);

    document.addEventListener('pointerleave', function (event) {
        if (event.pointerType !== 'mouse') { return; }
        var info = event.target.closest && event.target.closest('.info');
        if (info && !info.dataset.pinned && !info.contains(event.relatedTarget)) { setOpen(info, false); }
    }, true);

    document.addEventListener('focusin', function (event) {
        var info = event.target.closest && event.target.closest('.info');
        if (info) { open(info, false); }
    });

    document.addEventListener('focusout', function (event) {
        var info = event.target.closest && event.target.closest('.info');
        if (info && !info.dataset.pinned && !info.contains(event.relatedTarget)) { setOpen(info, false); }
    });

    /* A fixed popover would hang in mid-air while the page moves under it. */
    window.addEventListener('scroll', function () { closeAll(); }, true);
    window.addEventListener('resize', function () { closeAll(); });

    /* ---------------------------------------------------- long hints -> info */

    function fold(hint) {
        if (hint.dataset.infoDone || hint.hasAttribute('data-keep')) { return; }
        hint.dataset.infoDone = '1';

        var text = (hint.textContent || '').trim();
        if (text.length < MIN_LENGTH) { return; }
        if (hint.querySelector('a, button, input, select')) { return; }
        if (hint.matches('.is-danger, .is-error, .text-danger, [role="alert"]')) { return; }

        var field = hint.closest('.field, .lp-field, .rg-field, .form-group');
        if (!field) { return; }

        var label = field.querySelector('label');
        if (!label || label.querySelector('.info')) { return; }

        /* A control that is not a single input (a group of radios, a repeater)
           has no one thing to describe, so its hint stays put. */
        var control = field.querySelector('input:not([type="hidden"]), select, textarea');
        if (!control) { return; }

        var id = 'hint-info-' + (++uid);
        var wrap = document.createElement('span');
        wrap.className = 'info';
        wrap.setAttribute('data-info', '');
        wrap.innerHTML =
            '<button type="button" class="info-btn" aria-expanded="false" aria-controls="' + id + '" aria-label="About this field">' +
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
            '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>' +
            '</svg></button><span class="info-pop" role="tooltip" id="' + id + '"></span>';
        wrap.querySelector('.info-pop').textContent = text;

        label.appendChild(wrap);

        /* Screen readers read the explanation as the field's description. */
        var described = control.getAttribute('aria-describedby');
        control.setAttribute('aria-describedby', described ? described + ' ' + id : id);

        hint.hidden = true;
    }

    function scan(root) {
        var hints = (root.matches && root.matches('.form-hint')) ? [root] : [];
        if (root.querySelectorAll) {
            hints = hints.concat(Array.prototype.slice.call(root.querySelectorAll('.form-hint, .rg-hint')));
        }
        hints.forEach(fold);
    }

    scan(document);

    /* Modals and ajax fragments bring their own forms later. */
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) { scan(node); }
                });
            });
        }).observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
})();
