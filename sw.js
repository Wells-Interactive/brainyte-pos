const cacheName = 'restaurant-pos-v1';
const assets = [
  '/index.php',
  '/assets/css/style.css',
  '/assets/js/main.js',
  '/assets/js/waiter.js',
  '/assets/js/kitchen.js',
  '/assets/js/bar.js',
  '/manifest.webmanifest'
];
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(cacheName).then((cache) => cache.addAll(assets))
  );
});
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => cachedResponse || fetch(event.request))
  );
});
