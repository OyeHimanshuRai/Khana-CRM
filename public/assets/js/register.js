/*
|------------------------------------------------------------------------------
| Sign-up form: checked in the browser before it is sent
|------------------------------------------------------------------------------
|
| app.js posts the form without a reload and paints the server's errors onto
| the fields. This file adds the half that happens before anything is sent:
| each field is checked when the person leaves it, and again as they fix it,
| so "the passwords do not match" appears while they are still looking at the
| password - not after a round trip.
|
| The rules mirror RegisterController::store() exactly. The server is still
| the authority (it re-checks everything, and only it knows an email is
| taken); this only spares people a wait for the obvious.
|
| Uses the same markup as app.js - .is-invalid on the input, .field-error in
| the field - so both kinds of error look and clear the same way.
|
| Depends on: app.js (Toast, the data-ajax submit), app.css
*/
(function (window, document) {
    'use strict';

    var form = document.querySelector('form[data-register]');

    if (!form) {
        return;
    }

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var MOBILE = /^[0-9+\-\s()]{7,20}$/;

    /*
     | name => function(value, form) returning an error message or ''.
     | The same order and wording as the server's rules and messages.
     */
    var RULES = {
        business_name: function (v) {
            if (!v) { return 'Enter your business name.'; }
            return v.length > 150 ? 'Keep the business name under 150 characters.' : '';
        },
        name: function (v) {
            if (!v) { return 'Enter your name.'; }
            return v.length > 120 ? 'Keep your name under 120 characters.' : '';
        },
        mobile: function (v) {
            if (!v) { return 'Enter your mobile number.'; }
            return MOBILE.test(v) ? '' : 'Enter a valid phone number.';
        },
        email: function (v) {
            if (!v) { return 'Enter your email address.'; }
            if (v.length > 150) { return 'Keep the email under 150 characters.'; }
            return EMAIL.test(v) ? '' : 'Enter a valid email address, like name@example.com.';
        },
        password: function (v) {
            if (!v) { return 'Choose a password.'; }
            if (v.length < 8) { return 'The password must be at least 8 characters.'; }
            if (!/[A-Za-z]/.test(v) || !/[0-9]/.test(v)) {
                return 'The password must contain both letters and numbers.';
            }
            return '';
        },
        password_confirmation: function (v, f) {
            if (!v) { return 'Type the password again.'; }
            return v === f.elements.password.value ? '' : 'The two passwords do not match.';
        },
        terms: function (v, f) {
            return f.elements.terms.checked ? '' : 'Please accept the terms to continue.';
        },
        plan: function (v) {
            return v ? '' : 'Choose a plan.';
        }
    };

    /*
     | The plan is a dropdown; the chosen plan's one-line description is shown
     | under it, read from the option's data-blurb.
     */
    var planSelect = form.querySelector('[data-plan-select]');
    var planBlurb = form.querySelector('[data-plan-blurb]');
    var locationButton = form.querySelector('[data-get-location]');
    var locationLatitude = form.querySelector('[data-location-latitude]');
    var locationLongitude = form.querySelector('[data-location-longitude]');
    var locationDisplay = form.querySelector('#r-location');
    var locationStatus = form.querySelector('[data-location-status]');
    var logoInput = form.querySelector('[data-logo-input]');
    var logoName = form.querySelector('[data-logo-name]');

    function showBlurb() {
        if (!planSelect || !planBlurb) {
            return;
        }

        var option = planSelect.options[planSelect.selectedIndex];
        var text = option ? (option.getAttribute('data-blurb') || '') : '';

        planBlurb.textContent = text;
        planBlurb.hidden = text === '';
    }

    if (locationButton && locationLatitude && locationLongitude && locationDisplay && locationStatus) {
        locationButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                locationStatus.textContent = 'Location is not supported by this browser. You can still enter the address above.';
                return;
            }

            locationButton.disabled = true;
            locationStatus.textContent = 'Getting your current location…';

            navigator.geolocation.getCurrentPosition(function (position) {
                var latitude = position.coords.latitude.toFixed(7);
                var longitude = position.coords.longitude.toFixed(7);

                locationLatitude.value = latitude;
                locationLongitude.value = longitude;
                locationDisplay.value = latitude + ', ' + longitude;
                locationStatus.textContent = 'Location saved. Your GPS coordinates will be stored with the business.';
                locationButton.disabled = false;
            }, function (error) {
                var message = error.code === error.PERMISSION_DENIED
                    ? 'Location permission was denied. Allow it in your browser or enter the address above.'
                    : error.code === error.POSITION_UNAVAILABLE
                        ? 'Your location could not be determined. Enter the address above and try again.'
                        : 'Location request timed out. Please try again or enter the address above.';

                locationStatus.textContent = message;
                locationButton.disabled = false;
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 60000
            });
        });
    }

    if (logoInput && logoName) {
        logoInput.addEventListener('change', function () {
            var file = logoInput.files && logoInput.files[0];
            logoName.textContent = file
                ? file.name + ' · ' + (file.size / 1024 / 1024).toFixed(2) + ' MB'
                : 'JPG, PNG or WebP · up to 2 MB · used on invoices.';
        });
    }

    /* ------------------------------------------------------------ markup */

    function inputFor(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function container(input) {
        return input.closest('.field') || input.closest('label') || input.parentNode;
    }

    function clear(input) {
        var box = container(input);

        // Both kinds: ours, and the ones the server rendered on a no-JS post.
        Array.prototype.forEach.call(box.querySelectorAll('.field-error, .rg-error'), function (node) {
            node.parentNode.removeChild(node);
        });

        input.classList.remove('is-invalid');
        input.setAttribute('aria-invalid', 'false');
    }

    function mark(input, message) {
        clear(input);

        if (!message) {
            // Green only once it has been red: a fresh, empty-but-untouched
            // form should not look like a page of ticks.
            if (input.dataset.failed === '1') {
                input.classList.add('is-valid');
            }
            return;
        }

        input.dataset.failed = '1';
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');

        var span = document.createElement('span');
        span.className = 'field-error';
        span.setAttribute('role', 'alert');
        span.textContent = message;
        container(input).appendChild(span);
    }

    /* ------------------------------------------------------------- rules */

    function check(name) {
        var input = inputFor(name);

        if (!input || !RULES[name]) {
            return '';
        }

        var message = RULES[name]((input.value || '').trim(), form);
        mark(input, message);

        return message;
    }

    /*
     | The address is someone's sign-in, and "Owner@Cafe.com" and
     | "owner@cafe.com" are the same inbox. Shown lower-cased as they leave the
     | field, so what they see is what they will type to sign in. The server
     | lower-cases it again regardless.
     */
    function tidyEmail() {
        var email = inputFor('email');
        if (email) {
            email.value = email.value.trim().toLowerCase();
        }
    }

    /* ----------------------------------------------------------- events */

    // Leaving a field checks it.
    form.addEventListener('focusout', function (event) {
        var name = event.target.name;

        if (!RULES[name] || name === 'plan' || name === 'terms') {
            return;
        }

        if (name === 'email') {
            tidyEmail();
        }

        event.target.dataset.touched = '1';
        check(name);

        // The confirmation depends on the password; recheck it once it has
        // been filled, so fixing the first box clears the second's error.
        if (name === 'password' && inputFor('password_confirmation').dataset.touched === '1') {
            check('password_confirmation');
        }
    });

    // Typing in a field that has already been checked re-checks it, so the
    // red goes the moment the mistake is fixed rather than on the next blur.
    form.addEventListener('input', function (event) {
        var name = event.target.name;

        if (RULES[name] && event.target.dataset.touched === '1') {
            check(name);
        }

        if (name === 'password' && inputFor('password_confirmation').dataset.touched === '1') {
            check('password_confirmation');
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.name === 'plan') {
            showBlurb();
        }

        if (event.target.name === 'terms' || event.target.name === 'plan') {
            check(event.target.name);
        }
    });

    /*
     | Before app.js sends anything. This listener is on the form and app.js's
     | is on the document, so this one runs first; stopping the event here
     | keeps an obviously incomplete form from making the trip at all.
     */
    form.addEventListener('submit', function (event) {
        tidyEmail();

        var problems = [];
        var first = null;

        Object.keys(RULES).forEach(function (name) {
            var input = inputFor(name);
            if (input) {
                input.dataset.touched = '1';
            }

            var message = check(name);

            if (message) {
                problems.push(message);
                first = first || input;
            }
        });

        if (!problems.length) {
            return; // app.js takes it from here.
        }

        event.preventDefault();
        event.stopPropagation();

        if (first) {
            first.focus({ preventScroll: false });
        }

        if (window.Toast) {
            window.Toast.error(problems, { title: 'Please check the form' });
        }
    });

    /*
     | A server-side failure (email already taken, too many sign-ups) comes
     | back through app.js, which paints it. Marking that field as failed lets
     | the green tick appear once the person corrects it.
     */
    form.addEventListener('ajax:error', function (event) {
        var errors = (event.detail && event.detail.payload && event.detail.payload.errors) || {};

        Object.keys(errors).forEach(function (name) {
            var input = inputFor(name);
            if (input) {
                input.dataset.failed = '1';
                input.dataset.touched = '1';
            }
        });
    });
})(window, document);
