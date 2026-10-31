/*
| Direct messages: fetch only, no page reloads.
|
| New messages arrive by polling (every 3s while the tab is visible, every 15s
| otherwise). A send carries a client_uuid, so a retry after a network failure
| can never create the same message twice. Text goes in with textContent, never
| innerHTML.
*/
(function () {
    'use strict';

    var root = document.getElementById('msg');
    if (!root) { return; }

    var me = parseInt(root.dataset.me, 10);
    var canSend = root.dataset.canSend === '1';
    var listEl = document.getElementById('msg-list');
    var threadEl = document.getElementById('msg-thread');
    var formEl = document.getElementById('msg-form');
    var bodyEl = document.getElementById('msg-body');
    var sendEl = document.getElementById('msg-send');
    var titleEl = document.getElementById('msg-title');
    var searchEl = document.getElementById('msg-q');
    var backEl = document.getElementById('msg-back');
    var token = document.querySelector('meta[name="csrf-token"]').content;

    var active = null;      // conversation id
    var lastId = 0;
    var shown = {};         // message id or client_uuid -> true
    var sending = false;
    var timer = null;
    var searchTimer = null;

    function api(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token
        }, options.headers || {});
        options.credentials = 'same-origin';

        return fetch(url, options).then(function (res) {
            if (res.status === 401 || res.status === 419) {
                window.location.reload();
                throw new Error('Session expired');
            }
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok) {
                    var e = new Error(json.message || 'Request failed (' + res.status + ')');
                    e.status = res.status;
                    throw e;
                }
                return json.data || {};
            });
        });
    }

    function postJson(url, payload) {
        return api(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function fmt(iso) {
        var d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
    }

    function renderRow(name, sub, unread, onClick, current) {
        var li = el('li');
        var b = el('button');
        b.type = 'button';
        if (current) { b.setAttribute('aria-current', 'true'); }
        if (unread > 0) { b.appendChild(el('span', 'msg-badge', String(unread))); }
        b.appendChild(el('strong', null, name));
        b.appendChild(el('small', null, sub));
        b.addEventListener('click', onClick);
        li.appendChild(b);
        return li;
    }

    function loadConversations() {
        if (searchEl.value.trim() !== '') { return Promise.resolve(); }
        return api(root.dataset.urlConversations).then(function (d) {
            listEl.textContent = '';
            if (!d.conversations.length) {
                listEl.appendChild(el('li', 'msg-empty', 'No conversations yet. Search for a person above.'));
            }
            d.conversations.forEach(function (c) {
                listEl.appendChild(renderRow(c.with, c.preview, c.id === active ? 0 : c.unread,
                    function () { openThread(c.id, c.with); }, c.id === active));
            });
        }).catch(function () {
            // The next poll retries; until then a skeleton must not stand in for an answer.
            if (listEl.querySelector('.sk-stack')) {
                listEl.textContent = '';
                listEl.appendChild(el('li', 'msg-empty', 'Could not load people. Retrying…'));
            }
        });
    }

    function searchPeople() {
        var q = searchEl.value.trim();
        if (q === '') { return loadConversations(); }
        return api(root.dataset.urlContacts + '?q=' + encodeURIComponent(q)).then(function (d) {
            listEl.textContent = '';
            if (!d.contacts.length) { listEl.appendChild(el('li', 'msg-empty', 'Nobody found.')); }
            d.contacts.forEach(function (p) {
                listEl.appendChild(renderRow(p.name, 'Start a conversation', 0, function () { start(p.id); }, false));
            });
        }).catch(function (e) { listEl.textContent = ''; listEl.appendChild(el('li', 'msg-empty', e.message)); });
    }

    function start(userId) {
        postJson(root.dataset.urlStart, { user_id: userId }).then(function (d) {
            searchEl.value = '';
            openThread(d.id, d.with);
            loadConversations();
        }).catch(function (e) { alert(e.message); });
    }

    function openThread(id, name) {
        active = id; lastId = 0; shown = {};
        // The conversation's shape while it loads; poll() clears it when the messages land.
        if (window.Skeleton) { threadEl.innerHTML = window.Skeleton.thread(); } else { threadEl.textContent = ''; }
        titleEl.textContent = name;
        formEl.hidden = !canSend;
        root.classList.add('is-open');
        backEl.hidden = false;
        poll(true);
        if (canSend) { bodyEl.focus(); }
    }

    backEl.addEventListener('click', function () {
        root.classList.remove('is-open');
        backEl.hidden = true;
        active = null;
    });

    function addBubble(m, pending) {
        var key = m.client_uuid || m.id;
        if (shown[m.client_uuid] && !pending) {
            // Our optimistic bubble: promote it to the saved message.
            var mine = threadEl.querySelector('[data-uuid="' + m.client_uuid + '"]');
            if (mine) { mine.classList.remove('failed'); mine.dataset.id = m.id; stamp(mine, m); }
            return;
        }
        shown[key] = true;
        var b = el('div', 'msg-bubble' + (m.sender_id === me ? ' mine' : ''));
        b.dataset.uuid = m.client_uuid;
        b.dataset.id = m.id || '';
        b.appendChild(el('span', 'msg-text', m.body));
        b.appendChild(el('small', 'msg-meta', ''));
        stamp(b, m);
        threadEl.appendChild(b);
        threadEl.scrollTop = threadEl.scrollHeight;
        return b;
    }

    function stamp(bubble, m) {
        var meta = bubble.querySelector('.msg-meta');
        meta.textContent = m.id ? fmt(m.at) + (m.sender_id === me ? (m.read ? ' · read' : ' · sent') : '') : 'sending…';
    }

    function poll(force) {
        if (active === null) { return Promise.resolve(); }
        var id = active;
        return api(root.dataset.urlThread + '/' + id + '?after=' + lastId).then(function (d) {
            if (id !== active) { return; }
            var placeholder = threadEl.querySelector('.sk-stack');
            if (placeholder) { placeholder.remove(); }
            d.messages.forEach(function (m) {
                addBubble(m, false);
                if (m.id > lastId) { lastId = m.id; }
            });
            // Read ticks on our own messages.
            threadEl.querySelectorAll('.msg-bubble.mine').forEach(function (b) {
                var bid = parseInt(b.dataset.id, 10);
                if (bid && bid <= d.peer_read_up_to) {
                    var meta = b.querySelector('.msg-meta');
                    if (meta.textContent.indexOf('read') === -1) { meta.textContent = meta.textContent.replace('sent', 'read'); }
                }
            });
            if (!threadEl.children.length) { threadEl.appendChild(el('p', 'msg-empty', 'No messages yet. Say hello.')); }
            else { var empty = threadEl.querySelector('.msg-empty'); if (empty) { empty.remove(); } }
        }).catch(function (e) {
            var stuck = threadEl.querySelector('.sk-stack');
            if (stuck) { stuck.remove(); }
            if (e.status === 404) { active = null; titleEl.textContent = 'Conversation unavailable'; formEl.hidden = true; }
        });
    }

    formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var text = bodyEl.value.trim();
        if (sending || text === '' || active === null) { return; }

        sending = true;
        sendEl.disabled = true;
        var clientUuid = uuid();
        var bubble = addBubble({ body: text, sender_id: me, client_uuid: clientUuid }, true);
        bodyEl.value = '';

        postJson(root.dataset.urlThread + '/' + active + '/messages', { body: text, client_uuid: clientUuid })
            .then(function (d) {
                addBubble(d.message, false);
                if (d.message.id > lastId) { lastId = d.message.id; }
                loadConversations();
            })
            .catch(function (e) {
                bubble.classList.add('failed');
                bubble.querySelector('.msg-meta').textContent = 'Not sent: ' + e.message;
                bodyEl.value = bodyEl.value || text;
            })
            .then(function () { sending = false; sendEl.disabled = false; bodyEl.focus(); });
    });

    bodyEl.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); formEl.requestSubmit(); }
    });

    searchEl.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(searchPeople, 250);
    });

    function tick() {
        clearTimeout(timer);
        Promise.all([poll(false), loadConversations()]).then(schedule, schedule);
    }

    function schedule() {
        timer = setTimeout(tick, document.hidden ? 15000 : 3000);
    }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });

    // First paint of the list: people-shaped rows until the first answer.
    if (window.Skeleton) { listEl.innerHTML = window.Skeleton.rows(5); }
    loadConversations().then(schedule);
})();
