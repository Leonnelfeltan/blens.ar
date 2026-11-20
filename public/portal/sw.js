const APP = { icon: '/portal/icons/icon-192.png', fallback: '/portal/' };
function blensPushUrl(value) {
  try {
    const u = new URL(String(value || APP.fallback), self.location.origin);
    return u.origin === self.location.origin ? u.href : APP.fallback;
  } catch (_) {
    return APP.fallback;
  }
}
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/portal/media.php')) return;
});
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch (_) { data = { body: event.data ? event.data.text() : '' }; }
  if (!data || typeof data !== 'object') data = { body: String(data || '') };
  const title = typeof data.title === 'string' && data.title.trim() ? data.title : 'blens.ar · Portal';
  const body = typeof data.body === 'string' ? data.body : String(data.body || '');
  const options = {
    body: body,
    icon: APP.icon,
    badge: APP.icon,
    tag: typeof data.tag === 'string' && data.tag ? data.tag : 'blens-portal',
    data: { url: blensPushUrl(data.url) },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = blensPushUrl(event.notification.data && event.notification.data.url);
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) if (c.url === url && 'focus' in c) return c.focus();
    for (const c of list) {
      if ('navigate' in c && c.url.startsWith(self.location.origin)) return c.navigate(url).then(() => c.focus());
    }
    return self.clients.openWindow(url);
  }));
});
