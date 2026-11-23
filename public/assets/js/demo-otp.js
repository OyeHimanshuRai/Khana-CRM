/*
|------------------------------------------------------------------------------
| "Book a demo": confirm the email with a 6-digit code
|------------------------------------------------------------------------------
|
| Typing an email shows "Send OTP to this email"; the code that arrives is
| entered here and checked by the server, which remembers the confirmed address
| in the visitor's own session. An email that is given but not confirmed stops
| the form (demo-form.js asks, and DemoRequestController refuses it as well).
| A phone number alone needs no code.
*/
(function (window, document) {
    'use strict';

    var form = document.querySelector('form[data-demo-form]');
    var box = form ? form.querySelector('[data-otp]') : null;

    if (!form || !box) { return; }

    var emailInput = form.elements.email;
    var sendButton = box.querySelector('[data-otp-send]');
    var panel = box.querySelector('[data-otp-panel]');
    var codeInput = box.querySelector('[data-otp-input]');
    var verifyButton = box.querySelector('[data-otp-verify]');
    var resendButton = box.querySelector('[data-otp-resend]');
    var message = box.querySelector('[data-otp-msg]');
    var token = form.querySelector('input[name="_token"]');
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var timer = null;

    function current() { return String(emailInput.value || '').trim().toLowerCase(); }

    function say(text, kind) {
        message.textContent = text || '';
        message.hidden = !text;
        message.className = 'demo-otp-msg' + (kind ? ' is-' + kind : '');
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
            return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data }; });
        });
    }

    function reset() {
        clearInterval(timer);
        form.dataset.emailVerified = '';
        panel.hidden = true;
        codeInput.value = '';
        say('');
        // The send button is for an address that looks like one.
        sendButton.hidden = !EMAIL.test(current());
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
        if (!EMAIL.test(email)) { return; }

        sendButton.disabled = true;
        say('Sending the code…');

        post(box.dataset.sendUrl, { email: email }).then(function (res) {
            sendButton.disabled = false;
            var wait = res.data && res.data.data && res.data.data.wait;

            if (res.ok && res.data.success) {
                sendButton.hidden = true;
                panel.hidden = false;
                say(res.data.message, '');
                countdown(wait || 60);
                codeInput.value = '';
                codeInput.focus();
                return;
            }

            say((res.data && res.data.message) || 'Could not send the code. Please try again.', 'error');
            if (wait) { sendButton.hidden = true; panel.hidden = false; countdown(wait); }
        }).catch(function () {
            sendButton.disabled = false;
            say('Could not reach the server. Check your connection and try again.', 'error');
        });
    }

    function verifyCode() {
        var code = String(codeInput.value || '').replace(/\s+/g, '');

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
                form.dataset.emailVerified = current();
                panel.hidden = true;
                sendButton.hidden = true;
                say('Email verified.', 'ok');
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

    // A different address than the confirmed one: start again.
    emailInput.addEventListener('input', function () {
        if (form.dataset.emailVerified && current() === form.dataset.emailVerified) { return; }
        reset();
    });

    // A sent request empties the form; the confirmed address is forgotten by the server too.
    form.addEventListener('ajax:success', reset);

    reset();
})(window, document);
