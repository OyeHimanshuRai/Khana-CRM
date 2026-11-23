/*
|------------------------------------------------------------------------------
| The landing page's "Book a demo" form: checked in the browser first
|------------------------------------------------------------------------------
|
| app.js posts the form without a reload and paints any server error onto its
| field. This adds the half before anything is sent - each field checked as
| it is left, and the whole form on submit - with the same rules and words as
| DemoRequestController, which still re-checks everything.
|
| The email is required and confirmed with a code (demo-otp.js); the phone number is optional.
|
| On success the form is emptied (data-reset-on-success) and the thank-you
| shows under it as well as in the toast, for whoever looked away.
|
| Depends on: app.js (Toast, the data-ajax submit), app.css
*/
(function (window, document) {
    'use strict';

    var form = document.querySelector('form[data-demo-form]');

    if (!form) {
        return;
    }

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var PHONE = /^[0-9+\-\s()]{7,30}$/;

    function value(name) {
        var input = form.elements[name];
        return input ? String(input.value || '').trim() : '';
    }

    var RULES = {
        name: function (v) {
            if (!v) { return 'Tell us your name.'; }
            return v.length > 120 ? 'Keep the name under 120 characters.' : '';
        },
        phone: function (v) {
            return v && !PHONE.test(v) ? 'Enter a valid phone number.' : '';
        },
        email: function (v) {
            if (!v) { return 'Enter your email address.'; }
            return EMAIL.test(v) ? '' : 'Enter a valid email address, like name@example.com.';
        },
        business_name: function (v) {
            return v.length > 150 ? 'Keep the restaurant name under 150 characters.' : '';
        },
        city: function (v) {
            return v.length > 90 ? 'Keep the city under 90 characters.' : '';
        },
        outlets: function (v) {
            if (!v) { return ''; }
            var n = Number(v);
            return Math.floor(n) === n && n >= 1 && n <= 65535 ? '' : 'Enter a number of outlets, 1 or more.';
        },
        message: function (v) {
            return v.length > 2000 ? 'Keep the message under 2,000 characters.' : '';
        }
    };

    function container(input) {
        return input.closest('.field') || input.parentNode;
    }

    function mark(input, message) {
        var box = container(input);

        Array.prototype.forEach.call(box.querySelectorAll('.field-error'), function (node) {
            node.parentNode.removeChild(node);
        });

        input.classList.toggle('is-invalid', !!message);
        input.classList.toggle('is-valid', !message && input.dataset.failed === '1' && !!input.value);
        input.setAttribute('aria-invalid', message ? 'true' : 'false');

        if (message) {
            input.dataset.failed = '1';

            var span = document.createElement('span');
            span.className = 'field-error';
            span.setAttribute('role', 'alert');
            span.textContent = message;
            box.appendChild(span);
        }
    }

    function check(name) {
        var input = form.elements[name];
        if (!input || !RULES[name]) { return ''; }

        var message = RULES[name](value(name));
        mark(input, message);

        return message;
    }

    function checkPair(name) {
        check(name);
    }

    form.addEventListener('focusout', function (event) {
        var name = event.target.name;
        if (!RULES[name]) { return; }

        if (name === 'email') {
            event.target.value = event.target.value.trim().toLowerCase();
        }

        event.target.dataset.touched = '1';
        checkPair(name);
    });

    form.addEventListener('input', function (event) {
        if (RULES[event.target.name] && event.target.dataset.touched === '1') {
            checkPair(event.target.name);
        }
    });

    // On the form, so it runs before app.js's listener on the document.
    form.addEventListener('submit', function (event) {
        var problems = [];
        var first = null;

        if (form.elements.email) {
            form.elements.email.value = value('email').toLowerCase();
        }

        Object.keys(RULES).forEach(function (name) {
            var input = form.elements[name];
            if (input) { input.dataset.touched = '1'; }

            var message = check(name);
            if (message && problems.indexOf(message) === -1) {
                problems.push(message);
                first = first || input;
            }
        });

        // A given email must have been confirmed with the code (demo-otp.js).
        var typed = value('email');
        if (!problems.length && form.dataset.emailVerified !== typed) {
            problems.push('Verify your email with the 6-digit code first.');
            first = first || form.elements.email;
        }

        var thanks = form.parentNode.querySelector('[data-demo-thanks]') || document.querySelector('[data-demo-thanks]');
        if (thanks) { thanks.hidden = true; }

        if (!problems.length) {
            return; // app.js sends it.
        }

        event.preventDefault();
        event.stopPropagation();

        if (first) { first.focus({ preventScroll: false }); }

        if (window.Toast) {
            window.Toast.error(problems, { title: 'Please check the form' });
        }
    });

    form.addEventListener('ajax:success', function (event) {
        var thanks = document.querySelector('[data-demo-thanks]');
        var message = event.detail && event.detail.message;

        if (thanks && message) {
            thanks.textContent = message;
            thanks.hidden = false;
        }

        Array.prototype.forEach.call(form.querySelectorAll('.is-valid, .is-invalid'), function (node) {
            node.classList.remove('is-valid', 'is-invalid');
            delete node.dataset.failed;
            delete node.dataset.touched;
        });
    });
})(window, document);
