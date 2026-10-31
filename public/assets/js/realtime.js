/*
 | Live updates over a WebSocket (§8, §9, §14).
 |
 | ---------------------------------------------------------------------------
 | Why this is hand-written rather than Echo + pusher-js
 | ---------------------------------------------------------------------------
 |
 | Those two are the normal answer and they are good libraries. They are the
 | wrong answer here for two reasons:
 |
 |   1. This project has no build step. Every script in public/assets/js is
 |      plain ES5-ish JavaScript served as-is. Adding Echo means adding npm,
 |      a bundler and a build to a deploy that is currently "copy the files".
 |
 |   2. The screen that needs this most is a kitchen wall display, often on a
 |      cheap tablet, in a room whose internet is the same connection the card
 |      machine uses. Two CDN requests standing between a cook and their
 |      tickets is a bad trade for code that fits on two screens.
 |
 | Reverb speaks the Pusher protocol, and the part of it needed here -
 | connect, authorise, subscribe, receive, pong - is small and stable.
 |
 | ---------------------------------------------------------------------------
 | It is a nudge, never a source of truth
 | ---------------------------------------------------------------------------
 |
 | Nothing on any screen depends on a message arriving. Every listener also
 | polls; this only tells it not to wait. If the socket never connects, never
 | authorises, or dies at two in the morning, the screens behave exactly as
 | they did before this file existed - which is why the failure handling below
 | is quiet rather than loud.
 */
(function (window, document) {
    'use strict';

    var config = window.__realtime || null;

    // No config on the page means broadcasting is off. That is the default,
    // and a complete, working configuration - see config/broadcasting.php.
    if (!config || !config.key || !config.host || !('WebSocket' in window)) {
        return;
    }

    var socket = null;
    var socketId = null;
    var attempts = 0;
    var closing = false;

    /** channel name -> array of handlers */
    var handlers = {};

    /** channels already subscribed on the current connection */
    var joined = {};

    /* ------------------------------------------------------------- the wire */

    function url() {
        var scheme = config.scheme === 'https' ? 'wss' : 'ws';

        return scheme + '://' + config.host + ':' + config.port
            + '/app/' + config.key
            + '?protocol=7&client=js&version=8.0.0&flash=false';
    }

    function connect() {
        if (closing) { return; }

        try {
            socket = new WebSocket(url());
        } catch (e) {
            return retry();
        }

        socket.onopen = function () {
            attempts = 0;
        };

        socket.onmessage = function (event) {
            var frame;

            try {
                frame = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            handle(frame);
        };

        socket.onclose = function () {
            socketId = null;
            joined = {};
            announce(false);
            retry();
        };

        socket.onerror = function () {
            // onclose always follows, and that is where the retry lives.
            // Swallowed so a dead socket server does not fill a kitchen
            // tablet's console all evening.
        };
    }

    /**
     * Reconnect with a widening gap, capped.
     *
     * Capped at thirty seconds because the screens are still polling: there
     * is nothing to be gained by hammering a socket server that is down, and
     * a room full of tablets retrying every second is how a small outage
     * becomes a big one.
     */
    function retry() {
        if (closing) { return; }

        attempts += 1;

        var wait = Math.min(30000, 1000 * Math.pow(2, Math.min(attempts, 5)));

        window.setTimeout(connect, wait);
    }

    function send(payload) {
        if (socket && socket.readyState === 1) {
            socket.send(JSON.stringify(payload));
        }
    }

    /* ------------------------------------------------------------- protocol */

    function handle(frame) {
        var name = frame.event;

        if (name === 'pusher:ping') {
            return send({ event: 'pusher:pong', data: {} });
        }

        if (name === 'pusher:connection_established') {
            var data = parse(frame.data);
            socketId = data.socket_id;
            announce(true);

            // Re-join everything the page asked for before the socket was up,
            // and everything it had before a reconnect.
            Object.keys(handlers).forEach(join);
            return;
        }

        if (name === 'pusher:error' || name === 'pusher_internal:subscription_error') {
            /*
             | An auth failure, a bad key, a channel this person may not read.
             | Deliberately silent: the screens poll, so the consequence is
             | "not instant" rather than "broken", and a toast about sockets
             | means nothing to a cook.
             */
            return;
        }

        if (name && name.indexOf('pusher') !== 0) {
            dispatch(frame.channel, name, parse(frame.data));
        }
    }

    function parse(data) {
        if (typeof data !== 'string') { return data || {}; }

        try {
            return JSON.parse(data);
        } catch (e) {
            return {};
        }
    }

    /**
     * Authorise and subscribe to one private channel.
     *
     * The auth round-trip is the same one Echo makes, to the same endpoint
     * Laravel registers, signed with the same session cookie - so who may
     * listen is decided in routes/channels.php and nowhere else.
     */
    function join(channel) {
        if (!socketId || joined[channel]) { return; }

        joined[channel] = true;

        var body = new FormData();
        body.append('socket_id', socketId);
        body.append('channel_name', channel);

        fetch(config.authEndpoint || '/broadcasting/auth', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() },
            body: body
        }).then(function (response) {
            if (!response.ok) { throw new Error('auth refused'); }

            return response.json();
        }).then(function (payload) {
            send({
                event: 'pusher:subscribe',
                data: { channel: channel, auth: payload.auth }
            });
        }).catch(function () {
            // Let a later reconnect try again rather than giving up for good.
            joined[channel] = false;
        });
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    /* -------------------------------------------------------------- the API */

    function dispatch(channel, name, payload) {
        (handlers[channel] || []).forEach(function (fn) {
            try {
                fn(name, payload);
            } catch (e) {
                // One listener throwing must not stop the others, and must
                // not stop the socket.
            }
        });
    }

    /**
     * Tell the page whether live updates are actually working.
     *
     * The screens use this to decide how hard to poll: a KDS that has a live
     * socket drops to a slow heartbeat, and goes straight back to a real poll
     * the moment the socket dies. That is what makes the socket an
     * optimisation rather than a dependency.
     */
    function announce(live) {
        document.documentElement.classList.toggle('realtime-live', !!live);

        document.dispatchEvent(new CustomEvent('realtime:state', {
            detail: { live: !!live }
        }));
    }

    window.Realtime = {
        /**
         * Listen to a private channel.
         *
         * `name` is given without the `private-` prefix, the way it is
         * written in routes/channels.php.
         */
        listen: function (name, handler) {
            var channel = 'private-' + name;

            handlers[channel] = handlers[channel] || [];
            handlers[channel].push(handler);

            join(channel);
        },

        isLive: function () {
            return !!socketId;
        },

        stop: function () {
            closing = true;

            if (socket) { socket.close(); }
        }
    };

    connect();
})(window, document);
