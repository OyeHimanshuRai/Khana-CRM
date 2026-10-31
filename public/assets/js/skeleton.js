/*
 * Skeleton placeholders: the SHAPE of what is about to arrive, drawn while it
 * is on its way, instead of a word ("Loading...") or a spinner that says
 * nothing about what is coming.
 *
 * One builder per kind of surface, because they do not look alike:
 *
 *   Skeleton.form()      a dialog's fields and its buttons (modal.js)
 *   Skeleton.rows(n)     a list of people or notifications (bell, messages)
 *   Skeleton.thread()    a conversation: bubbles alternating sides
 *
 * Listings are not built here - their own rows turn into skeleton rows in CSS
 * (theme.css, [data-ajax-list-content].is-loading), so the table never changes
 * height and nothing flashes when the answer comes back fast.
 *
 * The markup is static and carries no user data, so it is safe as innerHTML.
 * Each placeholder announces itself once to a screen reader ("Loading") and is
 * otherwise silent; the shimmer is switched off for people who ask for less
 * motion.
 */
(function (window) {
    'use strict';

    function bar(cls, width, height) {
        var style = (width ? 'width:' + width + ';' : '') + (height ? 'height:' + height + ';' : '');
        return '<i class="sk ' + cls + '"' + (style ? ' style="' + style + '"' : '') + '></i>';
    }

    function wrap(cls, inner) {
        return '<div class="sk-stack ' + cls + '" role="status" aria-live="polite">' +
            '<span class="sr-only">Loading</span>' + inner + '</div>';
    }

    /* Label over a field, two across, three down - the shape of most forms here. */
    function form() {
        var widths = ['34%', '46%', '28%', '40%', '38%', '30%'];
        var cells = '';
        for (var i = 0; i < widths.length; i++) {
            cells += '<div class="sk-cell">' + bar('sk-line', widths[i]) + bar('sk-field') + '</div>';
        }
        return wrap('sk-form', '<div class="sk-grid">' + cells + '</div>' +
            '<div class="sk-actions">' + bar('sk-btn') + bar('sk-btn is-primary') + '</div>');
    }

    /* An avatar and two lines of text, as many times as asked. */
    function rows(count) {
        var widths = [['58%', '82%'], ['44%', '70%'], ['64%', '52%'], ['50%', '76%'], ['56%', '60%']];
        var out = '';
        for (var i = 0; i < (count || 4); i++) {
            var w = widths[i % widths.length];
            out += '<div class="sk-row">' + bar('sk-circle') +
                '<div class="sk-lines">' + bar('sk-line', w[0]) + bar('sk-line is-sm', w[1]) + '</div></div>';
        }
        return wrap('sk-rows', out);
    }

    /* Bubbles on alternating sides, so the eye reads it as a conversation. */
    function thread() {
        var spec = [['left', '52%'], ['right', '38%'], ['left', '66%'], ['right', '46%']];
        var out = '';
        spec.forEach(function (s) {
            out += '<div class="sk-bubble-row is-' + s[0] + '">' + bar('sk-bubble', s[1]) + '</div>';
        });
        return wrap('sk-thread', out);
    }

    window.Skeleton = { form: form, rows: rows, thread: thread };
})(window);
