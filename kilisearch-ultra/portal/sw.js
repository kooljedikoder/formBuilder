// Minimal offline shell for the standalone PWA — caches static assets only.
// index.php itself, and every /api/*.php call, are deliberately never
// cached: they carry live session/branding/search state that a stale
// cached copy would get wrong. Bump CACHE_NAME to invalidate old caches
// after changing this list.
const CACHE_NAME = 'kili-shell-v1';
const SHELL_ASSETS = [
  '../assets/css/kili.css',
  '../assets/js/kili.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const isShellAsset = SHELL_ASSETS.some((asset) => url.pathname.endsWith(asset.replace('../', '/')));

  if (!isShellAsset || event.request.method !== 'GET') {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return response;
      });
    })
  );
});
