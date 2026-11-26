/* Book a table: live slots on the booking page, and the Razorpay deposit (also used by "Pay now" on a booking). */
(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function call(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                json.status = response.status;
                if (response.status === 401) json.message = 'Please sign in to continue.';
                if (response.status === 419) json.message = 'Your session has expired. Please refresh the page.';
                if (!json.message && json.errors) json.message = Object.values(json.errors)[0][0];
                return json;
            });
        }).catch(function () {
            return { success: false, message: 'Could not reach the server. Check your connection and try again.' };
        });
    }

    /* Opens Razorpay for a held booking; on success, failure or close it reports back through `say`. */
    function pay(data, opts) {
        var say = opts.say || function () {};
        if (typeof window.Razorpay !== 'function' || !data.checkout) {
            say('The payment window could not open. Please refresh the page and try again.', 'bad');
            if (opts.onDone) opts.onDone(false);
            return;
        }
        var c = data.checkout;
        var settled = false;
        var rzp = new window.Razorpay({
            key: c.key,
            order_id: c.order_id,
            amount: c.amount,
            currency: c.currency,
            name: opts.shopName,
            description: 'Table booking advance',
            prefill: opts.prefill || {},
            config: {
                display: {
                    blocks: {
                        upi: { name: 'UPI: Google Pay, PhonePe, Paytm', instruments: [{ method: 'upi' }] },
                        other: { name: 'Cards, netbanking & wallets', instruments: [{ method: 'card' }, { method: 'netbanking' }, { method: 'wallet' }] }
                    },
                    sequence: ['block.upi', 'block.other'],
                    preferences: { show_default_blocks: false }
                }
            },
            handler: function (response) {
                settled = true;
                say('Payment received. Confirming your booking…', 'wait');
                call('POST', data.confirm_url, {
                    reference: data.reference,
                    razorpay_order_id: response.razorpay_order_id,
                    razorpay_payment_id: response.razorpay_payment_id,
                    razorpay_signature: response.razorpay_signature
                }).then(function (result) {
                    if (result.success) {
                        window.location.assign(result.redirect || data.show_url);
                        return;
                    }
                    if (!result.status) {
                        // Paid but the confirmation call was lost: the webhook confirms it, the booking page shows the result.
                        window.setTimeout(function () { window.location.assign(data.show_url); }, 3000);
                        return;
                    }
                    say(result.message, 'bad');
                    window.setTimeout(function () { window.location.assign(data.show_url); }, 2500);
                });
            },
            modal: {
                ondismiss: function () {
                    if (settled) return;
                    say('Payment not completed. Your table is held for a few minutes — you can pay from your booking.', 'wait');
                    if (opts.onDone) opts.onDone(false);
                }
            }
        });

        rzp.on('payment.failed', function (response) {
            settled = true;
            var reason = response && response.error ? response.error.description : '';
            call('POST', data.failed_url, { reference: data.reference, reason: reason }).then(function () {
                say((reason || 'The payment did not go through.') + ' You can try again while the table is held.', 'bad');
                if (opts.onDone) opts.onDone(false);
            });
        });

        rzp.open();
    }

    window.ShopBooking = { call: call, pay: pay };

    /* ------------------------------------------------------- booking page */
    var root = document.querySelector('[data-book]');
    if (!root) return;

    var state = { date: null, party: parseInt(root.dataset.party || '2', 10), time: null, deposit: 0 };
    var maxParty = parseInt(root.dataset.maxParty, 10);
    var days = JSON.parse(root.dataset.days);
    var dates = root.querySelector('[data-dates]');
    var slotsBox = root.querySelector('[data-slots]');
    var partyOut = root.querySelector('[data-party-out]');
    var submit = root.querySelector('[data-submit]');
    var message = root.querySelector('[data-message]');
    var form = root.querySelector('[data-details]');
    var loadToken = 0;

    function say(text, tone) {
        message.hidden = !text;
        message.textContent = text || '';
        message.className = 'book-status book-status-' + (tone || 'wait');
    }

    function money(n) { return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); }

    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

    function label(hhmm) {
        var p = hhmm.split(':'); var h = parseInt(p[0], 10);
        return ((h + 11) % 12 + 1) + ':' + p[1] + (h >= 12 ? ' PM' : ' AM');
    }

    function summary() {
        root.querySelector('[data-s-date]').textContent = state.date ? new Date(state.date + 'T00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) : '—';
        root.querySelector('[data-s-time]').textContent = state.time ? label(state.time) : '—';
        root.querySelector('[data-s-party]').textContent = state.party + (state.party === 1 ? ' guest' : ' guests');
        root.querySelector('[data-s-deposit]').textContent = state.deposit > 0 ? money(state.deposit) : 'No advance';
        submit.disabled = !(state.date && state.time);
        submit.textContent = state.deposit > 0 ? 'Pay ' + money(state.deposit) + ' & book' : 'Book table';
    }

    function renderDates() {
        var start = new Date(root.dataset.today + 'T00:00');
        var last = new Date(root.dataset.last + 'T00:00');
        var html = '';
        for (var d = new Date(start), i = 0; d <= last && i < 31; d.setDate(d.getDate() + 1), i++) {
            var value = iso(d);
            var open = days.indexOf(d.getDay()) !== -1;
            html += '<button type="button" class="book-date" data-date="' + value + '" aria-pressed="' + (state.date === value) + '"' + (open ? '' : ' disabled') + '>' +
                '<small>' + d.toLocaleDateString('en-IN', { weekday: 'short' }) + '</small><b>' + d.getDate() + '</b><small>' + d.toLocaleDateString('en-IN', { month: 'short' }) + '</small></button>';
        }
        dates.innerHTML = html;
    }

    function loadSlots() {
        if (!state.date) return;
        var token = ++loadToken;
        slotsBox.innerHTML = '<p class="book-note">Checking free tables…</p>';
        call('GET', root.dataset.slotsUrl + '?date=' + state.date + '&party_size=' + state.party).then(function (result) {
            if (token !== loadToken) return;
            if (!result.success) {
                slotsBox.innerHTML = '<p class="book-error"></p>';
                slotsBox.firstChild.textContent = result.message;
                state.time = null; summary();
                return;
            }
            state.deposit = result.data.deposit;
            var slots = result.data.slots;
            var keep = slots.some(function (s) { return s.time === state.time && s.available; });
            if (state.time && !keep) say('The time you picked was just taken. Please choose another.', 'bad');
            if (!keep) state.time = null;
            slotsBox.innerHTML = slots.length ? slots.map(function (s) {
                var note = s.reason === 'past' ? 'Too soon' : (s.reason === 'full' ? 'Full' : 'Free');
                return '<button type="button" class="book-slot" data-time="' + s.time + '" aria-pressed="' + (state.time === s.time) + '"' + (s.available ? '' : ' disabled') + '>' + label(s.time) + '<small>' + note + '</small></button>';
            }).join('') : '<p class="book-note">No times on this day.</p>';
            var anyFree = slots.some(function (s) { return s.available; });
            // Today already over (or full) on first load: move to the next open day instead of showing a dead page.
            if (!anyFree && state.autoAdvance) {
                var next = dates.querySelector('[data-date="' + state.date + '"] ~ [data-date]:not([disabled])');
                if (next) { state.date = next.dataset.date; renderDates(); loadSlots(); return; }
            }
            state.autoAdvance = false;
            if (!anyFree) slotsBox.insertAdjacentHTML('beforeend', '<p class="book-note">No free times on this day. Please try another date or fewer guests.</p>');
            summary();
        });
    }

    dates.addEventListener('click', function (e) {
        var b = e.target.closest('[data-date]');
        if (!b || b.disabled) return;
        state.date = b.dataset.date; state.time = null; say('');
        renderDates(); loadSlots(); summary();
    });

    root.querySelector('[data-party-minus]').addEventListener('click', function () { if (state.party > 1) { state.party--; partyOut.textContent = state.party; loadSlots(); summary(); } });
    root.querySelector('[data-party-plus]').addEventListener('click', function () { if (state.party < maxParty) { state.party++; partyOut.textContent = state.party; loadSlots(); summary(); } else { say('For more than ' + maxParty + ' guests, please call the restaurant.', 'wait'); } });

    slotsBox.addEventListener('click', function (e) {
        var b = e.target.closest('[data-time]');
        if (!b || b.disabled) return;
        state.time = b.dataset.time; say('');
        slotsBox.querySelectorAll('[data-time]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        summary();
    });

    // Other guests book too: refresh the times while the page is open.
    window.setInterval(function () { if (state.date && document.visibilityState === 'visible' && !submit.dataset.busy) loadSlots(); }, 20000);

    submit.addEventListener('click', function () {
        if (root.dataset.signedIn !== '1') {
            window.location.assign(root.dataset.loginUrl);
            return;
        }
        var name = form.querySelector('[name="name"]').value.trim();
        var mobile = form.querySelector('[name="mobile"]').value.trim();
        if (name.length < 2) { say('Please enter the name for the booking.', 'bad'); return; }
        if (!/^\+?[0-9\s-]{10,18}$/.test(mobile)) { say('Please enter a valid mobile number.', 'bad'); return; }

        submit.disabled = true; submit.dataset.busy = '1';
        say('Holding your table…', 'wait');
        call('POST', root.dataset.storeUrl, {
            date: state.date, time: state.time, party_size: state.party,
            name: name, mobile: mobile, notes: form.querySelector('[name="notes"]').value.trim()
        }).then(function (result) {
            if (!result.success) {
                say(result.message, 'bad');
                delete submit.dataset.busy; summary(); loadSlots();
                if (result.status === 401) window.location.assign(root.dataset.loginUrl);
                return;
            }
            if (result.data.paid) { window.location.assign(result.redirect); return; }
            say('Opening secure payment…', 'wait');
            pay(result.data, {
                shopName: root.dataset.shopName,
                prefill: { name: name, contact: mobile, email: root.dataset.email || '' },
                say: say,
                onDone: function () { window.setTimeout(function () { window.location.assign(result.data.show_url); }, 2500); }
            });
        });
    });

    renderDates();
    var firstOpen = dates.querySelector('[data-date]:not([disabled])');
    if (firstOpen) { state.date = firstOpen.dataset.date; state.autoAdvance = true; renderDates(); loadSlots(); }
    partyOut.textContent = state.party;
    summary();
})();
