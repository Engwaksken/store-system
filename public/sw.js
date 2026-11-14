/*
 * Service worker for browser push notifications (Construction CMS).
 * It only shows pushes and opens the linked page; it does not cache pages or work offline.
 */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Store system', body: event.data ? event.data.text() : '' }; }
    var title = data.title || 'Store system';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        icon: '/favicon.ico',
        badge: '/favicon.ico',
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
