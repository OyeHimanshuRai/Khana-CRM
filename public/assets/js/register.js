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
            if (!MOBILE.test(v)) { return 'Enter a valid phone number.'; }

            // The country's real numbering plan (libphonenumber-js), for the country chosen above.
            var country = selectedCountry();
            if (window.libphonenumber && country) {
                var phone = null;
                try { phone = window.libphonenumber.parsePhoneNumberFromString(v, country.iso); } catch (e) { phone = null; }

                if (phone === undefined || phone === null) {
                    // A territory with no numbering plan on file: only the length can be checked.
                    var digits = v.replace(/\D+/g, '');
                    if (digits.length < country.min || digits.length > country.max) {
                        return 'Enter a valid ' + country.name + ' mobile number.';
                    }
                } else {
                    // Valid for this country, and a mobile (not a landline or a toll-free number).
                    var type = phone.getType();
                    var valid = phone.isValid()
                        && String(phone.countryCallingCode) === String(country.code)
                        && (!phone.country || phone.country === country.iso)
                        && (type === 'MOBILE' || type === 'FIXED_LINE_OR_MOBILE');
                    if (!valid) { return 'Enter a valid ' + country.name + ' mobile number.'; }
                }
            }

            return '';
        },
        country_id: function (v) {
            return v ? '' : 'Choose a country.';
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

    /*
     | The plan's price follows the billing period: "Chain - Rs 4,999/mo" when Monthly is chosen,
     | "Chain - Rs 49,990/yr" when Yearly is. The tag on Yearly says what it saves for the chosen plan.
     */
    function refreshPricing() {
        var select = form.querySelector('[data-period-select]');
        if (!planSelect || !select) { return; }

        var yearly = select.value === 'yearly';

        Array.prototype.forEach.call(planSelect.options, function (option) {
            var text = option.getAttribute(yearly ? 'data-yearly' : 'data-monthly');
            if (text) { option.textContent = text; }
        });

        var chosen = planSelect.options[planSelect.selectedIndex];
        var save = chosen ? parseInt(chosen.getAttribute('data-save'), 10) || 0 : 0;

        Array.prototype.forEach.call(form.querySelectorAll('.rg-seg-tag'), function (tag) {
            tag.textContent = save > 0 ? 'Save ' + save + '%' : '';
            tag.hidden = save <= 0;
        });
    }

    function showBlurb() {
        if (!planSelect || !planBlurb) {
            return;
        }

        var option = planSelect.options[planSelect.selectedIndex];
        var text = option ? (option.getAttribute('data-blurb') || '') : '';

        planBlurb.textContent = text;
        planBlurb.title = option ? (option.getAttribute('data-full') || text) : '';
        planBlurb.hidden = text === '';
    }

    /*
     | Billing, as a Monthly / Yearly switch. The <select> stays the real
     | control - it is what is submitted, and what is there without JavaScript -
     | and is only hidden; the buttons just set its value.
     */
    var periodSelect = form.querySelector('[data-period-select]');

    if (periodSelect) {
        var seg = document.createElement('div');
        seg.className = 'rg-seg';
        seg.setAttribute('role', 'radiogroup');
        seg.setAttribute('aria-label', 'Billing period');

        var segButtons = Array.prototype.map.call(periodSelect.options, function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'radio');
            button.dataset.value = option.value;
            button.appendChild(document.createTextNode(option.value === 'yearly' ? 'Yearly' : 'Monthly'));

            if (option.value === 'yearly') {
                var tag = document.createElement('span');
                tag.className = 'rg-seg-tag';
                tag.textContent = 'Save';
                button.appendChild(tag);
            }

            button.addEventListener('click', function () {
                periodSelect.value = option.value;
                paintSeg();
            });

            seg.appendChild(button);

            return button;
        });

        var paintSeg = function () {
            segButtons.forEach(function (button) {
                button.setAttribute('aria-checked', button.dataset.value === periodSelect.value ? 'true' : 'false');
            });

            refreshPricing();
        };

        periodSelect.classList.add('rg-seg-source');
        periodSelect.tabIndex = -1;
        periodSelect.parentNode.insertBefore(seg, periodSelect.nextSibling);
        periodSelect.addEventListener('change', paintSeg);
        paintSeg();
    }

    /*
     | The name of the place at a pair of coordinates (OpenStreetMap's Nominatim), e.g.
     | "Koregaon Park, Pune, Maharashtra". Asked of the visitor's own browser only after they
     | press the location button; when it cannot be had the numbers are shown instead.
     */
    function placeName(latitude, longitude) {
        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=16&addressdetails=1&accept-language=en'
            + '&lat=' + encodeURIComponent(latitude) + '&lon=' + encodeURIComponent(longitude);

        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.address) { return data && data.display_name ? data.display_name : ''; }

                var a = data.address;
                var parts = [
                    a.suburb || a.neighbourhood || a.quarter || a.road || a.hamlet,
                    a.city || a.town || a.village || a.municipality || a.county,
                    a.state
                ].filter(function (part, i, all) { return part && all.indexOf(part) === i; });

                return parts.length ? parts.join(', ') : (data.display_name || '');
            })
            .catch(function () { return ''; });
    }

    if (locationButton && locationLatitude && locationLongitude && locationDisplay && locationStatus) {
        locationButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                locationStatus.textContent = 'Location is not supported by this browser. You can still enter the address above.'; locationStatus.hidden = false;
                return;
            }

            locationButton.disabled = true;
            locationStatus.textContent = 'Getting your current location…'; locationStatus.hidden = false;

            navigator.geolocation.getCurrentPosition(function (position) {
                var latitude = position.coords.latitude.toFixed(7);
                var longitude = position.coords.longitude.toFixed(7);

                locationLatitude.value = latitude;
                locationLongitude.value = longitude;
                // Shown as a place name, not as numbers; the coordinates are still kept (hidden fields above).
                locationDisplay.value = 'Finding the place name…';
                locationStatus.textContent = 'Location saved. Your GPS coordinates will be stored with the business.'; locationStatus.hidden = false;
                locationButton.disabled = false;

                placeName(latitude, longitude).then(function (name) {
                    locationDisplay.value = name || (latitude + ', ' + longitude);
                });
            }, function (error) {
                var message = error.code === error.PERMISSION_DENIED
                    ? 'Location permission was denied. Allow it in your browser or enter the address above.'
                    : error.code === error.POSITION_UNAVAILABLE
                        ? 'Your location could not be determined. Enter the address above and try again.'
                        : 'Location request timed out. Please try again or enter the address above.';

                locationStatus.textContent = message; locationStatus.hidden = false;
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
            logoName.textContent = file ? file.name + ' · ' + (file.size / 1024 / 1024).toFixed(2) + ' MB' : '';
            logoName.hidden = !file;
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
    });

    // Typing in a field that has already been checked re-checks it, so the
    // red goes the moment the mistake is fixed rather than on the next blur.
    form.addEventListener('input', function (event) {
        var name = event.target.name;

        if (RULES[name] && event.target.dataset.touched === '1') {
            check(name);
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.name === 'plan') {
            showBlurb();
            refreshPricing();
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

    /* --------------------------------------- country > state > city, phone code */

    /*
     | The lists come from the database through /geo/...: picking a country loads
     | its states, picking a state loads its cities, and each change empties what
     | depends on it. The calling code beside the mobile box follows the country.
     */
    var countrySelect = form.querySelector('[data-geo-country]');
    var stateSelect = form.querySelector('[data-geo-state]');
    var citySelect = form.querySelector('[data-geo-city]');
    var phoneCode = form.querySelector('[data-phone-code]');
    var phoneIso = form.querySelector('[data-phone-iso]');
    var phoneFlag = form.querySelector('[data-phone-flag]');
    var phoneSelect = form.querySelector('[data-phone-select]');

    function selectedCountry() {
        if (!countrySelect) { return null; }

        var option = countrySelect.options[countrySelect.selectedIndex];
        if (!option || !option.value) { return null; }

        return {
            id: option.value,
            name: option.textContent.trim(),
            iso: option.getAttribute('data-iso'),
            code: option.getAttribute('data-code'),
            min: parseInt(option.getAttribute('data-min'), 10),
            max: parseInt(option.getAttribute('data-max'), 10)
        };
    }

    function reset(select, placeholder, disabled) {
        select.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        select.disabled = !!disabled;
        select.classList.remove('is-invalid');
    }

    function fill(select, rows, label, wanted) {
        rows.forEach(function (row) {
            var option = document.createElement('option');
            option.value = row.id;
            option.textContent = row[label];
            if (wanted && String(row.id) === String(wanted)) { option.selected = true; }
            select.appendChild(option);
        });
    }

    function load(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    if (!r.ok || !data.success) { throw new Error(data.message || 'Could not load the list.'); }
                    return data.data;
                });
            });
    }

    function fail(error) {
        if (window.Toast) { window.Toast.error(error && error.message ? error.message : 'Could not load the list. Please try again.'); }
    }

    function loadCities(wanted) {
        reset(citySelect, 'City', true);

        if (!stateSelect.value) { return Promise.resolve(); }

        citySelect.options[0].textContent = 'Loading cities...';

        return load(stateSelect.dataset.citiesUrl.replace('__ID__', encodeURIComponent(stateSelect.value))).then(function (rows) {
            reset(citySelect, rows.length ? 'City' : 'No cities listed', !rows.length);
            fill(citySelect, rows, 'city_name', wanted);
        }).catch(function (error) {
            reset(citySelect, 'City', true);
            fail(error);
        });
    }

    function loadStates(wantedState, wantedCity) {
        reset(stateSelect, 'State', true);
        reset(citySelect, 'City', true);

        var country = selectedCountry();
        if (!country) { return Promise.resolve(); }

        stateSelect.options[0].textContent = 'Loading states...';

        return load(countrySelect.dataset.statesUrl.replace('__ID__', encodeURIComponent(country.id))).then(function (rows) {
            reset(stateSelect, rows.length ? 'State' : 'No states listed', !rows.length);
            fill(stateSelect, rows, 'state_name', wantedState);

            return wantedState ? loadCities(wantedCity) : null;
        }).catch(function (error) {
            reset(stateSelect, 'State', true);
            fail(error);
        });
    }

    // The calling code (and, for India, its flag) beside the mobile box.
    function showPhoneCode() {
        var country = selectedCountry();
        if (!country || !phoneCode) { return; }

        phoneCode.textContent = '+' + country.code;
        if (phoneSelect) { phoneSelect.value = country.id; }

        if (phoneFlag && phoneIso) {
            var india = country.iso === 'IN';
            phoneFlag.style.display = india ? '' : 'none';
            phoneIso.hidden = india;
            phoneIso.textContent = country.iso;
        }

        // The invisible list covers exactly the visible code, whatever its width.
        var prefix = form.querySelector('[data-phone-prefix]');
        if (phoneSelect && prefix) { phoneSelect.style.width = prefix.offsetWidth + 'px'; }

        var mobile = inputFor('mobile');
        if (mobile) {
            mobile.setAttribute('maxlength', String(Math.max(20, country.max + 8)));
            if (mobile.dataset.touched === '1') { check('mobile'); }
        }
    }

    if (countrySelect && stateSelect && citySelect) {
        countrySelect.addEventListener('change', function () {
            showPhoneCode();
            loadStates();
        });

        stateSelect.addEventListener('change', function () {
            loadCities();
        });

        // Picking a country from the calling-code list picks it in the Country list.
        if (phoneSelect) {
            phoneSelect.addEventListener('change', function () {
                countrySelect.value = phoneSelect.value;
                countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        showPhoneCode();
        loadStates(stateSelect.dataset.old, citySelect.dataset.old);
    }

    /* ------------------------------------------------ email one-time code */

    /*
     | The Register button only appears once the email has been confirmed with a
     | code mailed to it. The server checks the same thing (a confirmed address
     | is remembered in the visitor's own session), so this is the friendly half.
     */
    (function () {
        var box = form.querySelector('[data-otp]');
        var emailInput = inputFor('email');
        var submit = form.querySelector('[data-register-submit]');
        var gate = form.querySelector('[data-register-gate]');

        if (!box || !emailInput || !submit) { return; }

        var sendButton = box.querySelector('[data-otp-send]');
        var panel = box.querySelector('[data-otp-panel]');
        var codeInput = box.querySelector('[data-otp-input]');
        var verifyButton = box.querySelector('[data-otp-verify]');
        var resendButton = box.querySelector('[data-otp-resend]');
        var message = box.querySelector('[data-otp-msg]');
        var token = form.querySelector('input[name="_token"]');
        var verifiedEmail = '';
        // The address the server has confirmed in this visit; typing it back restores the button.
        var confirmedEmail = box.dataset.verifiedEmail || '';
        var timer = null;

        function say(text, kind) {
            message.textContent = text || '';
            message.hidden = !text;
            message.className = 'rg-otp-msg' + (kind ? ' is-' + kind : '');
        }

        function current() {
            return (emailInput.value || '').trim().toLowerCase();
        }

        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token ? token.value : ''
                },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function setVerified(on) {
            verifiedEmail = on ? current() : '';
            submit.hidden = !on;
            if (gate) { gate.hidden = on; }
            sendButton.hidden = on;
            panel.hidden = true;
            if (on) { say('Email verified. You can register now.', 'ok'); }
        }

        function countdown(seconds) {
            clearInterval(timer);
            var left = seconds;
            resendButton.disabled = true;
            resendButton.textContent = 'Resend OTP in ' + left + 's';
            timer = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(timer);
                    resendButton.disabled = false;
                    resendButton.textContent = 'Resend OTP';
                    return;
                }
                resendButton.textContent = 'Resend OTP in ' + left + 's';
            }, 1000);
        }

        function sendCode() {
            var email = current();
            var problem = RULES.email(email, form);

            if (problem) {
                mark(emailInput, problem);
                emailInput.focus();
                return;
            }

            sendButton.disabled = true;
            resendButton.disabled = true;
            say('Sending the code…');

            post(box.dataset.sendUrl, { email: email }).then(function (res) {
                sendButton.disabled = false;

                if (res.ok && res.data.success) {
                    // Asking for a code for another address makes the server forget the confirmed one.
                    if (email !== confirmedEmail) { confirmedEmail = ''; }
                    sendButton.hidden = true;
                    panel.hidden = false;
                    say(res.data.message, '');
                    countdown((res.data.data && res.data.data.wait) || 60);
                    codeInput.value = '';
                    codeInput.focus();
                    return;
                }

                var wait = res.data && res.data.data && res.data.data.wait;
                say((res.data && res.data.message) || 'Could not send the code. Please try again.', 'error');
                if (wait) { panel.hidden = false; sendButton.hidden = true; countdown(wait); } else { resendButton.disabled = false; }
            }).catch(function () {
                sendButton.disabled = false;
                resendButton.disabled = false;
                say('Could not reach the server. Check your connection and try again.', 'error');
            });
        }

        function verifyCode() {
            var code = (codeInput.value || '').replace(/\s+/g, '');

            if (!/^\d{6}$/.test(code)) {
                say('Enter the 6-digit code from the email.', 'error');
                codeInput.focus();
                return;
            }

            verifyButton.disabled = true;

            post(box.dataset.verifyUrl, { email: current(), otp: code }).then(function (res) {
                verifyButton.disabled = false;

                if (res.ok && res.data.success) {
                    clearInterval(timer);
                    clear(emailInput);
                    confirmedEmail = current();
                    setVerified(true);
                    return;
                }

                say((res.data && res.data.message) || 'That code is not right.', 'error');
                codeInput.select();
            }).catch(function () {
                verifyButton.disabled = false;
                say('Could not reach the server. Check your connection and try again.', 'error');
            });
        }

        sendButton.addEventListener('click', sendCode);
        resendButton.addEventListener('click', sendCode);
        verifyButton.addEventListener('click', verifyCode);
        codeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); verifyCode(); }
        });
        codeInput.addEventListener('input', function () {
            codeInput.value = codeInput.value.replace(/\D+/g, '').slice(0, 6);
        });

        // Another address than the confirmed one: start again.
        emailInput.addEventListener('input', function () {
            if (verifiedEmail !== '' && current() === verifiedEmail) { return; }

            if (confirmedEmail !== '' && current() === confirmedEmail) {
                clearInterval(timer);
                setVerified(true);
                return;
            }

            clearInterval(timer);
            submit.hidden = true;
            if (gate) { gate.hidden = false; }
            sendButton.hidden = false;
            panel.hidden = true;
            verifiedEmail = '';
            say('');
        });

        // Refreshed after confirming: the session still remembers the address.
        if (confirmedEmail !== '' && confirmedEmail === current()) {
            setVerified(true);
        }
    })();
})(window, document);
