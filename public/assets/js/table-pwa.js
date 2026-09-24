/*
|------------------------------------------------------------------------------
| Making the guest's menu installable (§14)
|------------------------------------------------------------------------------
|
| §14 asks for "installable PWA can be considered for repeat customers", and
| this is the whole of it: register a service worker, and let the browser
| offer its own install prompt.
|
| ------------------------------------------------------------------------------
| The thing that makes a restaurant PWA dangerous
| ------------------------------------------------------------------------------
|
| A service worker's normal job is to serve content from a cache. On a menu
| that is exactly wrong: sold-out toggling is the feature §8 asks for by name,
| and a cached menu shows a dish that ran out an hour ago. The guest orders it,
| the kitchen cannot make it, and somebody has to walk over and explain.
|
| So this worker caches the stylesheet and the icons and NOTHING ELSE. Every
| page, every price and every availability check goes to the network, exactly
| as it does without a worker. What installing buys is a home-screen icon and
| a faster first paint - not offline ordering, which is not a thing a
| restaurant should want.
|
| If the network is down the guest sees the browser's own offline page, which
| is honest. An offline menu that let somebody build a cart they could never
| send would be worse than no menu.
*/
(function (window, document, navigator) {
    'use strict';

    if (!('serviceWorker' in navigator)) { return; }

    var url = document.querySelector('meta[name="sw-url"]');

    if (!url) { return; }

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(url.getAttribute('content'), { scope: '/' })
            .catch(function () {
                /*
                 | Registration fails on http:// outside localhost, in private
                 | windows, and wherever site data is blocked. All normal, and
                 | none of them should put anything in front of a hungry
                 | guest: without a worker the page simply behaves as it
                 | always has.
                 */
            });
    });
})(window, document, navigator);
