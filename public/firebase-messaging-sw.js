/*
|------------------------------------------------------------------------------
| Firebase Cloud Messaging service worker - the admin panel's notifications
|------------------------------------------------------------------------------
|
| Shows a notification when a message arrives and no admin tab is open: a new
| table order on the owner's phone in their pocket, a sign-up on the platform
| admin's laptop. When a tab is open, firebase-push.js handles the message
| instead and the bell updates in place.
|
| Registered by firebase-push.js with scope /admin/, NOT the site root. The
| guest menu's own worker (sw.js) owns the root, and two workers on one scope
| replace each other - the guest PWA and the owner's notifications would take
| turns breaking.
|
| The Firebase config arrives in this file's query string. A worker cannot
| read the page it serves, and baking the keys into a static file would mean
| editing JavaScript to change projects; the config is public anyway.
|
| Pinned to one SDK version, the compat build because a worker loads it with
| importScripts().
*/

importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

const params = new URL(self.location.href).searchParams;

const config = {
    apiKey: params.get('apiKey'),
    authDomain: params.get('authDomain'),
    projectId: params.get('projectId'),
    storageBucket: params.get('storageBucket') || undefined,
    messagingSenderId: params.get('messagingSenderId'),
    appId: params.get('appId'),
};

if (config.apiKey && config.projectId && config.messagingSenderId && config.appId) {
    firebase.initializeApp(config);

    const messaging = firebase.messaging();

    /*
     | Messages from FcmService carry a `notification` block, which the SDK
     | displays by itself, link and all (webpush.fcm_options.link). This only
     | handles a data-only message, should one ever be sent.
     */
    messaging.onBackgroundMessage((payload) => {
        if (payload.notification) {
            return;
        }

        const data = payload.data || {};

        return self.registration.showNotification(data.title || 'New notification', {
            body: data.body || '',
            tag: data.type || 'alert',
            data: { url: data.url || null },
        });
    });
}

self.addEventListener('notificationclick', (event) => {
    const url = event.notification.data && event.notification.data.url;

    if (!url) {
        return;
    }

    event.notification.close();

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => client.url === url && 'focus' in client);

            return open ? open.focus() : self.clients.openWindow(url);
        }),
    );
});
