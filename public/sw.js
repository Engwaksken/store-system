/*
 * Service worker (Construction CMS): makes the site installable as an app (PWA) and shows push notifications.
 *  - Pages are always loaded from the server (they carry live business data and are never cached).
 *    Only when the device is offline does a page request get the small offline screen below.
 *  - Static files (CSS, JS, icons, fonts) are served from the network and kept as a fallback copy.
 */
var CACHE = 'cms-shell-v1';
var OFFLINE_URL = '/offline.html';

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll([OFFLINE_URL]); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function isStaticAsset(url) {
    return url.origin === self.location.origin && /^\/(css|js|vendor|icons|pwa|storage\/branding|storage\/fonts)\//.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') { return; }
    var url = new URL(req.url);

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(function () {
            return caches.match(OFFLINE_URL);
        }));
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(fetch(req).then(function (res) {
            if (res && res.ok) {
                var copy = res.clone();
                caches.open(CACHE).then(function (c) { c.put(req, copy); });
            }
            return res;
        }).catch(function () {
            return caches.match(req).then(function (hit) { return hit || Response.error(); });
        }));
    }
});

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Store system', body: event.data ? event.data.text() : '' }; }
    var title = data.title || 'Store system';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        icon: '/pwa/icon-192.png',
        badge: '/pwa/icon-192.png',
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : '/', self.location.origin);
    if (target.origin !== self.location.origin) { target = new URL('/', self.location.origin); }
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (wins) {
        for (var i = 0; i < wins.length; i++) {
            if ('focus' in wins[i] && 'navigate' in wins[i]) {
                return wins[i].navigate(target.href).then(function (w) { return w && w.focus(); });
            }
        }
        return self.clients.openWindow(target.href);
    }));
});
