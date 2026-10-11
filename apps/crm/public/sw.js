/*
|------------------------------------------------------------------------------
| Service worker for the guest menu (§14)
|------------------------------------------------------------------------------
|
| Deliberately the least a service worker can do and still make a page
| installable.
|
| ------------------------------------------------------------------------------
| Nothing dynamic is ever cached
| ------------------------------------------------------------------------------
|
| A cached menu is a menu showing a dish that sold out an hour ago. §8 asks for
| an instant sold-out toggle by name, and a worker that served pages from a
| cache would quietly undo it - the guest orders, the kitchen cannot make it,
| and somebody walks over to explain.
|
| So the rule below has no exceptions: only same-origin GETs for files under
| /assets/ are cached, and everything else goes to the network untouched. A
| menu that cannot be reached shows the browser's own offline page, which is
| honest. An offline menu that let somebody build a cart they could never send
| would be worse than no menu at all.
|
| The cache name carries a version. Changing it is how an old asset cache is
| thrown away on the next visit.
*/

const CACHE = 'guest-assets-v1';

/** Only these are ever stored. Everything else is network, always. */
const CACHEABLE = /\/assets\/(css|js|img|icons)\//;

self.addEventListener('install', (event) => {
    // No pre-caching: the asset list changes with every deploy and a stale
    // pre-cache is the thing this worker exists to avoid.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names.filter((name) => name !== CACHE).map((name) => caches.delete(name)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Anything that is not a plain same-origin GET for an asset is none of
    // this worker's business.
    if (request.method !== 'GET') { return; }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin || !CACHEABLE.test(url.pathname)) {
        return;
    }

    /*
     | Cache first, for assets only.
     |
     | Safe because every asset URL here carries a ?v= cache-buster derived
     | from the file's own modification time - a changed file is a different
     | URL, so a stale one can never be served.
     */
    event.respondWith(
        caches.match(request).then((hit) => hit || fetch(request).then((response) => {
            if (!response || response.status !== 200 || response.type !== 'basic') {
                return response;
            }

            const copy = response.clone();

            caches.open(CACHE).then((cache) => cache.put(request, copy));

            return response;
        })),
    );
});
