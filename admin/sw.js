// Pearlecent admin app: shows new-lead notifications, even when the app is closed or the phone is locked.
// Pages are never cached on the phone, so patient details stay on the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'New appointment request', {
    body: d.body || 'Open Pearlecent to see the details.',
    icon: 'icons/icon-192.png',
    badge: 'icons/badge-96.png',
    tag: d.tag || 'lead',
    renotify: true,
    requireInteraction: false,
    vibrate: [180, 80, 180],
    data: { url: d.url || './?p=leads' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || './?p=leads', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
    for (const w of wins) {
      if (w.url.startsWith(self.registration.scope)) { w.navigate(url); return w.focus(); }
    }
    return self.clients.openWindow(url);
  }));
});

self.addEventListener('pushsubscriptionchange', (e) => {
  // The phone renewed its address; the admin page re-registers it the next time it opens.
  e.waitUntil(self.registration.showNotification('Pearlecent', { body: 'Open the app once to keep notifications on.', icon: 'icons/icon-192.png', tag: 'resub' }));
});
