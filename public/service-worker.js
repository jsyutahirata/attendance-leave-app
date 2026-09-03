const CACHE_NAME = 'attendance-shell-v9';
const SHELL_ASSETS = [
  '/assets/app.css?v=20260903-5',
  '/assets/app.js?v=20260903-5',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/offline.html'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname === '/index.php' || event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
    return;
  }
  event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
});

self.addEventListener('push', event => {
  const fallback = {title: '社内勤怠管理', body: '勤怠に関するお知らせがあります。', url: '/index.php?route=attendance'};
  let data = fallback;
  try { data = {...fallback, ...event.data.json()}; } catch (_) {}
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: '/assets/icons/icon-192.png',
    badge: '/assets/icons/icon-192.png',
    data: {url: data.url},
    tag: data.tag || 'attendance-notice'
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = event.notification.data?.url || '/index.php';
  event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(windows => {
    const existing = windows.find(client => new URL(client.url).origin === self.location.origin);
    return existing ? existing.navigate(target).then(client => client.focus()) : clients.openWindow(target);
  }));
});
